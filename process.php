<?php

// log to file
//fclose(STDOUT);
//fclose(STDERR);
//fclose($STDOUT);
//fclose($STDERR);
//$STDOUT = fopen('application.log', 'wb');
//$STDERR = fopen('error.log', 'wb');

require_once('vendor/autoload.php');
require_once('config.php');
require_once('ParldataAPI.php');

define('ORG_CLASSIFICATION_ZESPOL_SENACKI', 'friendship group');
define('ORG_CLASSIFICATION_KLUB', 'parliamentary group');
define('ORG_CLASSIFICATION_KOMISJA', 'committee');

use epforgpl\parsers\ParserException;

# An hour should be enough
set_time_limit(3600);
mb_internal_encoding('utf-8');

$updater = new SenatUpdater();

$updater->run(array_slice($argv, 1));


class ScraperException extends \Exception {
}

class SenatUpdater {
    function __construct() {
        $this->api = new parldata\API(API_ENDPOINT, SCRAPER_USER, SCRAPER_PASSWORD, 'ParlDataPHPApi/1.0 SenatParser/1.2');
        $this->parser = new epforgpl\parsers\Senat();
        $this->errors = array();
        $this->current_chamber = null;
        $this->current_chamber_has_senators = false;

        $this->chambers = array();
    }

    private function mapPerson($web) {
        $person = array(
            'id' => $web['id'],
            'name' => $web['name'],
            'given_name' => $web['given_name'],
            'family_name' => $web['family_name'],
            'gender' => $web['gender'],
            'image' => $web['photo'],
            'identifiers' => array(array(
                'scheme' => 'senat.gov.pl',
                'identifier' => $web['id']
            )),
            'sources' => array(array('url' => $web['url']))
        );

        if (has_key($web, 'additional_name') and $web['additional_name']) {
            $person['additional_name'] = $web['additional_name'];
        }

        return $person;
    }

    private function mapPersonInfo($person_data) {
        $person = array(
            'national_identity' => 'Polish',
            'biography' => $person_data['bio_note'],
            'birth_date' => $person_data['birth_date']
        );

        $contact_details = array();
        if ($person_data['email']) {
            array_push($contact_details, array(
                'label' => 'E-mail',
                'type' => 'email',
                'value' => $person_data['email']
            ));
        }
        if ($person_data['www']) {
            array_push($contact_details, array(
                'label' => 'Website',
                'type' => 'url',
                'value' => $person_data['www']
            ));
        }

        if (!empty($contact_details)) {
            $person['contact_details'] = $contact_details;
        }

        return $person;
    }

    function crontab() {
        $class = new ReflectionClass(get_class($this));
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
        $offset = 0;
        $step = intval(60 / (count($methods) - 3));

        echo "# sudo php process.php crontab > /etc/cron.d/scrapers-poland-senat\n";
        foreach($methods as $method) {
            if (!in_array($method->name, array('__construct', 'crontab', 'run'))) {
                echo "$offset * * * * root php " . __FILE__ . ' ' . $method->name . "\n";
                $offset += $step;
            }
        }
    }

    function run($args) {
        if (count($args) != 1 || !$args[0]) {
            echo "Updater takes one argument: job_name";
            return;
        }

        $job = $args[0];

        if (!method_exists($this, $job)) {
            throw new Exception("Unknown job: " . $job);
        }

        if ($job == 'crontab') {
            return $this->crontab();
        }

        $log = array(
            'label' => $job,
            'status' => 'running',
            'params' => array()
        );

        $this->api->createLog($log);

        try {
            $terms = $this->parser->getTermsOfOffice();
            if ($terms[0]['id'] == 'VII') {
                // add current term
                array_unshift($terms, array(
                    'id' => 'VIII',
                    'start_date' => '2011-10-09',
                    'source' => 'http://www.senat.gov.pl/o-senacie/senat-wspolczesny/dane-o-senatorach-wg-stanu-na-dzien-wyborow/'
                ));

                $this->ensureChambersCreated($terms);

            } else {
                // TODO maybe automatic term_id increment and setting dates
                throw new Exception('New term of office has arrived! Please fill info about the current');
            }

            $this->api->commit();
            $this->{$job}();

            if (empty($this->errors)) {
                $log['status'] = 'finished';
                $this->api->createLog($log);

            } else {
                $log['status'] = 'failed';
                $log['params']['errors'] = $this->errors;

                $this->api->createLog($log);
            }

        } catch (Exception $ex) {
            $this->api->rollback();

            $log['params']['errors'] = array($ex->getMessage());
            $log['status'] = 'failed';
            $this->api->createLog($log);

            throw $ex;
        }
    }

    function updateSenatorsList() {
        $list = array();
        foreach ($this->parser->updateSenatorsList() as $w) {
            $list[$w['id']]['web'] = $w;
        }
        foreach ($this->api->findPeople(array('all' => true))->_items as $a) {
            $list[$a->id]['api'] = $a;
        }

        foreach ($list as $id => $data) {
            if (has_key($data, 'web')) {
                if (!has_key($data, 'api')) {
                    // add new person
                    $person = self::mapPerson($data['web']);
                    $this->api->createPerson($person);
                }
            }
        }

        $this->api->update('organizations', $this->current_chamber, array(
            'sources' => $this->mergeFlatDictsObj('note', $this->chambers[$this->current_chamber]->sources, array((object) array(
               'note' => 'senators_url',
               'url' => $this->parser->urlSenatorsList()
            )))
        ));
    }

    function updateSenators() {
        $cache_organizations = array();

        foreach ($this->api->find('organizations', array('all' => true))->_items as $a) {
            $cache_organizations[$a->id] = $a;
        }

        $senators = $this->api->findPeople(array(
            'all' => true,
            'where' => array(
                'identifiers.scheme' => 'senat.gov.pl' // speakers not from Senate don't have this id
            )
        ))->_items;

        foreach ($senators as $a) {
            $id_senator = $a->id;

            $person_data = $this->parser->updateSenatorInfo($id_senator);
            $person = self::mapPersonInfo($person_data);

            $this->api->updatePerson($id_senator, $person);

            // Download and map all memberships
            $person = $this->api->getPerson($id_senator, array('embed' => array('memberships.person')));

            $existing_memberships = array();
            if (isset($person->memberships)) {
                foreach ($person->memberships as $m) {
                    $m->_found = false;
                    $existing_memberships[$m->id] = $m;
                }
            }

            // Kadencje - terms of office
            foreach ($person_data['cadencies'] as $wterm) {
                $id_chamber = 'chamber_' . strtoupper(($wterm));

                // memberships
                $membership_id = $id_chamber . '-' . $id_senator;

                if (has_key($existing_memberships, $membership_id)) {
                    $existing_memberships[$membership_id]->_found = true;

                    if (!empty($person_data['mandate_end_date'])
                        and empty($existing_memberships[$membership_id]->end_date)
                        and $id_chamber == $this->current_chamber
                    ) {

                        $this->api->update('memberships', $membership_id,
                            array('end_date' => $person_data['mandate_end_date']));
                    }

                } else {
                    // create membership
                    $membership = array(
                        'id' => $membership_id,
                        'person_id' => $id_senator,
                        'organization_id' => $id_chamber,
                    );
                    if (defined('FIRST_PASS') and !FIRST_PASS) {
                        // we can assume that if there's new connection it happened today
                        $membership['start_date'] = $this->today();
                    }

                    $this->api->createMembership($membership);
                }
            }

            $that = $this;
            $updateMemberships = function ($id_senator, $org_classification, $weborg)
            use(&$cache_organizations, &$existing_memberships, $that) {
                $id_group = str_replace(' ', '_', $org_classification) . '_' . $weborg['id'];

                // create if needed
                if (!has_key($cache_organizations, $id_group)) {
                    $org = array(
                        'id' => $id_group,
                        'name' => $weborg['name'],
                        'classification' => $org_classification,
                        'parent_id' => $that->current_chamber,
                        'sources' => array(array(
                            'url' => $weborg['url']
                        ))
                    );
                    $that->api->createOrganization($org);

                    $cache_organizations[$id_group] = (object) $org;
                }

                // memberships
                $membership_id = $id_group . '-' . $id_senator;

                if (has_key($existing_memberships, $membership_id)) {
                    $m = $existing_memberships[$membership_id];
                    $m->_found = true;
                    if (!isset($m->end_date) and isset($weborg['end_date'])) {
                        $m->end_date = $weborg['end_date'];
                        $that->api->update('memberships', $m->id, array('end_date' => $weborg['end_date']));
                    }
                    if (!isset($m->start_date) and isset($weborg['start_date'])) {
                        $m->start_date = $weborg['start_date'];
                        $that->api->update('memberships', $m->id, array('start_date' => $weborg['start_date']));
                    }

                } else {
                    // create membership
                    $membership = array(
                        'id' => $membership_id,
                        'person_id' => $id_senator,
                        'organization_id' => $id_group,
                    );
                    if (isset($weborg['end_date'])) {
                        $membership_id['end_date'] = $weborg['end_date'];
                    }
                    if (isset($weborg['start_date'])) {
                        $membership_id['start_date'] = $weborg['start_date'];
                    }

                    if (defined('FIRST_PASS') and !FIRST_PASS) {
                        // we can assume that if there's new connection it happened today
                        $membership['start_date'] = $this->today();
                    }

                    $that->api->createMembership($membership);
                }
            };

            // Kluby - senat clubs
            foreach ($person_data['clubs'] as $klub) {
                $updateMemberships($id_senator, ORG_CLASSIFICATION_KLUB, $klub, $this->api);
            }

            // Komisje senackie - committee
            foreach ($person_data['committees'] as $committee) {
                $updateMemberships($id_senator, ORG_CLASSIFICATION_KOMISJA, $committee, $this->api);
            }

            // Zespoły senackie
            foreach ($person_data['senat_assemblies'] as $weborg) {
                $updateMemberships($id_senator, ORG_CLASSIFICATION_ZESPOL_SENACKI, $weborg, $this->api);
            }

            // delete old memberships
            foreach ($existing_memberships as $m) {
                if (!$m->_found) {
                    $this->api->update('memberships', $m->id, array(
                        'end_date' => $this->today()
                    ));
                }
            }

            $this->api->commit();
        }
    }


    function updateSittingsList() {
        // cannot start when there's no people scraped yet
        if (!$this->current_chamber_has_senators) {
            return;
        }

        $list = array();
        foreach ($this->parser->updateMeetingsList() as $w) {
            $list['sitting_' . $w['number']]['web'] = $w;
        }
        $sittings = $this->api->find('events', array(
            'all' => true,
            'where' => array(
                'type' => 'sitting',
                'organization_id' => $this->current_chamber
            )
        ));

        foreach ($sittings->_items as $a) {
            $list[$a->id]['api'] = $a;
        }

        foreach ($list as $id => $data) {
            if (has_key($data, 'web')) {
                if (!has_key($data, 'api')) {
                    // add new sitting
                    $w = $data['web'];

                    $event = array(
                        'id' => 'sitting_' . $w['number'],
                        'type' => 'sitting',
                        'organization_id' => $this->current_chamber,
                        'identifier' => $w['id'], // source identifier
                        'name' => $w['name'],
                        'start_date' => $w['dates'][0] . 'T00:00:00',
                        'end_date' => $w['dates'][count($w['dates']) - 1] . 'T00:00:00',
                        'sources' => array(array('url' => $w['topics_url']))
                    );
                    $id_sitting_parent = $this->api->create('events', $event);

                    $day = 0;
                    foreach ($w['dates'] as $date) {
                        $day++;
                        $event = array(
                            'id' => 'sitting_' . $w['number'] . '_' . $day,
                            'parent_id' => $id_sitting_parent,
                            'type' => 'sitting',
                            'organization_id' => $this->current_chamber,
                            'identifier' => $w['id'] . ',' . $day, // source identifier
                            'name' => $w['name'] . ' - Dzień ' . $day,
                            'start_date' => $date . 'T00:00:00',
                            'end_date' => $date . 'T00:00:00'
                        );

                        $this->api->create('events', $event);
                    }
                }
            }
        }
    }

    /**
     * Fills all info about bunch of sittings
     */
    function updateSittings() {
        $batch = 20;

        $sittings = $this->api->find('events', array(
            'max_results' => min($batch, 50),
            'where' => array(
                'type' => 'sitting',
                'sources' => array(
                    '$exists' => false
                )
            )
        ));

        $name2id = array();
        foreach ($this->api->findPeople(array('all' => true))->_items as $p) {
            $name2id[$p->name] = $p->id;
        }

        foreach ($sittings->_items as $sitting) {
            try {
                $id_sitting = $sitting->id;
                $stenogram = $this->parser->getStenogram($sitting->identifier);
                $source = $stenogram['source'];
                $node = $stenogram['node'];

                $pos = 0;
                $speeches = array();
                $speech = null;
                $speech_text = '';
                $time = $sitting->start_date; // TODO update with time
                $close_now = false;

                foreach ($node->children() as $line) {
                    $text = trim($line->plaintext);
                    if (empty($text)) {
                        continue;
                    }

                    $matches = array();
                    $elemType = $line->tag . '.' . $line->class;

                    if ($close_now or in_array($elemType, array('p.centr-P', 'p.haslo-P', 'h3.'))) {
                        // close speech fragment
                        if ($speech) {
                            array_push($speeches, array_merge($speech, array(
                                'id' => $id_sitting . '-' . $pos,
                                'text' => $speech_text,
                                'date' => $time,
                                'position' => $pos,
                                'event_id' => $id_sitting,
                                'sources' => array(array('url' => $source))
                            )));

                            $speech_text = '';
                            $speech = null;
                            $pos++;
                            $close_now = false;
                        }
                    }

                    switch ($elemType) {
                        case 'p.centr-P':
                            if (!preg_match('/^\\((.*)\\)?$/', $text, $matches)) {
                                throw new ParserException("Was expecting parentheses around [.centr-P]: " . $text);
                            }
                            $speech_text = trim($matches[1]);
                            $speech = array('type' => 'narrative');
                            $close_now = true;

                            // (Początek posiedzenia o godzinie 9 minut 02)
                            // (Przerwa w obradach od godziny 13 minut 01 do godziny 14 minut 00)
                            // (Przerwa w posiedzeniu o godzinie 21 minut 50)
                            break;

                        case 'p.haslo-P':
                        case 'h3.':
                            // speaker definition
                            $text = trim(trim($text), ':');

                            $id_speaker = null;
                            foreach ($name2id as $_name => $_id) {
                                if (endsWith($text, $_name)) {
                                    $id_speaker = $_id;
                                    break;
                                }
                            }

                            try {
                                $name_idx = $this->parser->findIndexOfName($text);

                            } catch (ParserException $ex) {
                                // that's an exception!
                                if ($text == 'Wicemarszałek Pańczyk-Pozdziej') {
                                    $person = array(
                                        'name' => 'Pańczyk-Pozdziej',
                                        'family_name' => 'Pańczyk-Pozdziej',
                                        'summary' => $role = 'Wicemarszałek'
                                    );

                                    $id_speaker = $this->api->createPerson($person);
                                    $name2id[$person['name']] = $id_speaker;
                                    $name_idx = 14;
                                } else {
                                    throw $ex;
                                }
                            }

                            $role = trim(substr($text, 0, $name_idx));

                            if ($id_speaker == null) {
                                $person = $this->parser->parseFullName(trim(substr($text, $name_idx)));
                                $person['summary'] = $role;

                                $id_speaker = $this->api->createPerson($person);
                                $name2id[$person['name']] = $id_speaker;
                            }

                            $speech = array(
                                'type' => 'speech',
                                'creator_id' => $id_speaker,
                                'role' => $role
                            );

                            break;

                        // Table of Contents - $line->id - skip
                        case 'h1.stenogram-ukryj-naglowek':
                        case 'h2.stenogram-ukryj-naglowek':
                        case 'h3.stenogram-ukryj-naglowek':
                        case 'h4.stenogram-ukryj-naglowek':
                            break;

                        // Junk
                        case 'script.':
                            break;

                        case 'p.bodytext-P':
                        case 'p.pos-P':
                        case 'p.oskierow-P':
                            // actual speech
                            $speech_text .= ($speech_text != '' ? ' ' : '') . $text;

                            // <a href="#" class="jq-szczegoly-glosowania" rel="id_1813">Głosowanie nr 2</a>
                            break;

                        default:
                            throw new ParserException("Unrecognized speech element: " . $elemType);
                    }
                } // end of stenogram

                $this->api->create('speeches', $speeches);
                $this->api->update('events', $sitting->id, array(
                    'sources' => array(array('url' => $source))
                ));

            } catch (Exception $ex) {
                $err = $ex->getMessage() . ' in ' . $this->parser->urlSittingStenogram($sitting->identifier);
                error_log($err);
                array_push($this->errors, $err);
            }
        } // end of batch
    }

    // TODO przemowienia, np. http://senat.gov.pl/prace/senat/posiedzenia/przebieg,19,2,przemowienia.html

    /**
     * Updates vote events for some sittings
     * All vote events of given sitting are processed at once (all or none goes into API)
     *
     * @see http://www.senat.gov.pl/gfx/senat/pl/senatopracowania/29/plik/ot-611.pdf
     */
    function updateVoteEvents() {
        $batch = 20;

        $sittings = $this->api->find('events', array(
            'max_results' => min($batch, 50),
            'where' => array(
                'sources.note' => array('$ne' => 'vote_events'), // note=vote_events marks processed sittings
                'parent_id' => array('$exists' => false)
            ),
            'sort' => 'start_date'
        ));

        if (empty($sittings->_items)) {
            return;
        }

        // cache motions
        $motion2id = array();

        foreach ($sittings->_items as $sitting) {
            $this->api->commit();

            try {
                $id_sitting = $sitting->id;

                $vote_events = array();
                $votings = $this->parser->updateMeetingVotings($sitting->identifier);

                foreach ($votings as $webvote) {
                    $id_vote_event = $id_sitting . '-' . $webvote['no'];

                    $id_motion = null;
                    if (has_key($webvote, 'motion')) {
                        if (has_key($motion2id, $webvote['motion'])) {
                            $id_motion = $motion2id[$webvote['motion']];

                        } else {
                            // optimalization: batch create motions
                            $motion = $this->api->getOrCreate('motions', array(
                                'identifier' => $webvote['motion']
                            ), array(
                                'organization_id' => $this->current_chamber,
                                // sittings are processed chronologically so we can suppose it was proposed at first processed sitting
                                'legislative_session_id' => $id_sitting,
                                'identifier' => $webvote['motion'], // identifier == title
                                'date' => $sitting->start_date,
                                'sources' => array(array('url' => $webvote['source']))
                            ));

                            $motion2id[$webvote['motion']] = $id_motion = $motion->id;
                        }
                    }

                    $vote_event = array(
                        'id' => $id_vote_event,
                        'organization_id' => $this->current_chamber,
                        'legislative_session_id' => $id_sitting,
                        'identifier' => $sitting->identifier . ',' . $webvote['no'],
                        'start_date' => $sitting->start_date,
                        // 'result' => 'pass|fail' if needed parse http://senat.gov.pl/prace/senat/posiedzenia/tematy,19,1.html from sources
                        // 'group_results' if needed search SenatParser for results_clubs_url
                        'sources' => array(array(
                            'note' => 'votes-to-parse',
                            'url' => $webvote['results_people_url']
                        ), array(
                            'url' => $webvote['source']
                        ))
                    );

                    if ($id_motion != null) {
                        $vote_event['motion_id'] = $id_motion;
                    }

                    array_push($vote_events, $vote_event);
                }

                if (!empty($vote_events)) {
                    $this->api->create('vote-events', $vote_events);
                }

                $this->api->update('events', $id_sitting, array(
                    'sources' => array(array(
                        'url' => $this->parser->urlMeetingsVotings($sitting->identifier, 1),
                        'note' => 'vote_events' // note=vote_events marks processed sittings
                    ))
                ));
                $this->api->commit();

            } catch (ParserException $ex) {
                $this->api->rollback();

                $err = $ex->getMessage() . ' in ' . $this->parser->urlMeetingsVotings($sitting->identifier, 1) . ' or following days';
                error_log($err);
                array_push($this->errors, $err);

            } catch (Exception $ex) {
                $this->api->rollback();
                throw $ex;
            }
        } // end of batch
    }

    /**
     * Updates votings for some vote events
     * All votes of given vote-event are processed at once (all or none goes into API)
     *
     * @see http://www.senat.gov.pl/gfx/senat/pl/senatopracowania/29/plik/ot-611.pdf
     */
    function updateVotes() {
        $batch = 20;

        $vote_events = $this->api->find('vote-events', array(
            'max_results' => min($batch, 50),
            'where' => array(
                'sources.note' => 'votes-to-parse',
            ),
            'sort' => 'start_date'
        ));

        if (empty($vote_events->_items)) {
            return;
        }

        // cache names with initials as specified on votings pages
        $name2id = array();
        foreach ($this->api->findPeople(array('all' => true, 'where' => array('identifiers.scheme' => 'senat.gov.pl')))->_items as $p) {
            $i1 = ((isset($p->given_name) and !empty($p->given_name)) ? mb_substr($p->given_name, 0, 1) . '.' : '');
            $i2 = ((isset($p->additional_name) and !empty($p->additional_name)) ? mb_substr($p->additional_name, 0, 1) . '.' : '');

            $name2id[mb_strtolower($i1 . $i2 . $p->family_name)] = $p->id;
        }

        foreach ($vote_events->_items as $vote_event) {
            $this->api->commit();

            $url = null;
            foreach ($vote_event->sources as $s) {
                if ($s->note == 'votes-to-parse') {
                    $url = $s->url;
                    $s->note = 'votes';
                    break;
                }
            }
            if ($url == null) {
                throw new ScraperException("votes-to-parse should be set for this object");
            }

            try {
                $votes = array();

                $id_vote_event = $vote_event->id;
                $results = $this->parser->updatePeopleVotes($url);

                $counts = array();
                foreach ($results['grouped'] as $option => $value) {
                    if ($option == 'present') {
                        continue;
                    }
                    array_push($counts, array(
                        'option' => $option,
                        'value' => $value
                    ));
                }

                foreach ($results['votes'] as $vote) {
                    $voter_key = mb_strtolower($vote['initials'][0] . '.' . $vote['family_name']);
                    if (!has_key($name2id, $voter_key)) {
                        // if it didn't work with single initial, try both

                        $voter_key = mb_strtolower(join('.', $vote['initials']) . '.' . $vote['family_name']);
                        if (!has_key($name2id, $voter_key)) {
                            throw new ParserException("Couldn't find voter: $voter_key");
                        }
                    }
                    $id_voter = $name2id[$voter_key];

                    $vote = array(
                        'id' => $id_vote_event . '-' . $id_voter,
                        'vote_event_id' => $id_vote_event,
                        'voter_id' => $id_voter,
                        'option' => $vote['vote'],
                    );
                    array_push($votes, $vote);
                }

                if (!empty($votes)) {
                    $this->api->create('votes', $votes);
                }

                $this->api->update('vote-events', $id_vote_event, array(
                    'counts' => $counts,
                    'sources' => $vote_event->sources
                ));
                $this->api->commit();

            } catch (ParserException $ex) {
                $this->api->rollback();

                $err = $ex->getMessage() . ' in ' . $url . ' or following days';
                error_log($err);
                array_push($this->errors, $err);

            } catch (Exception $ex) {
                $this->api->rollback();
                throw $ex;
            }
        } // end of batch
    }

    private function debug($msg) {
        if (defined('DEBUG') and DEBUG) {
            echo $msg . "\n";
        }
    }

    private function warn($msg) {
        error_log("[WARNING] " . $msg);
    }

    private function senator_url($id) {
        return 'http://senat.gov.pl/sklad/senatorowie/senator,' . $id . '.html';
    }

    private function today() {
        $date = new DateTime();
        return $date->format('Y-m-d');
    }

    // TODO new task: close old chambers memberships

    private function ensureChambersCreated($terms) {
        $this->chambers = array();
        foreach ($this->api->find('organizations', array('where' => array('classification' => 'chamber')))->_items as $chamber) {
            $this->chambers[$chamber->id] = $chamber;
        }

        $this->current_chamber = 'chamber_' . $terms[0]['id'];

        if (has_key($this->chambers, $this->current_chamber)) {
            foreach($this->chambers[$this->current_chamber]->sources as $s) {
                if (isset($s->note) and $s->note == 'senators_url') {
                    $this->current_chamber_has_senators = true;
                    break;
                }
            }
        }

        foreach ($terms as $term) {
            $chamber_id = 'chamber_' . $term['id'];

            if (!has_key($this->chambers, $chamber_id)
                or (isset($term['end_date']) and $this->chambers[$chamber_id]->dissolution_date != $term['end_date'])
            ) {

                $org = array(
                    'id' => $chamber_id,
                    'name' => 'Senat - kadencja ' . $term['id'],
                    'classification' => 'chamber',
                    'founding_date' => $term['start_date'],
                    'sources' => array(array(
                        'note' => 'chamber',
                        'url' => $term['source']
                    ))
                );
                if (has_key($term, 'end_date')) {
                    $org['dissolution_date'] = $term['end_date'];
                }

                $this->api->createOrganization($org);
                $this->chambers[$chamber_id] = (object) $org;
            }
        }
    }

    private function mergeFlatDictsObj($key, array $sources, array $sources2) {
        $tmp = array();
        foreach($sources as $s) {
            if (!isset($s->{$key})) {
                throw new ScraperException("Key $key must be set");
            }
            $tmp[$s->{$key}] = $s;
        }
        foreach($sources2 as $s) {
            $tmp[$s->{$key}] = $s;
        }

        $ret = array();
        foreach($tmp as $k => $obj) {
            array_push($ret, $obj);
        }
        return $ret;
    }
}

function has_key($Arr, $key) {
    return isset($Arr[$key]) || array_key_exists($key, $Arr);
}

function startsWith($haystack, $needle) {
    // search backwards starting from haystack length characters from the end
    return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
}

function endsWith($haystack, $needle) {
    return substr($haystack, -strlen($needle)) === $needle;
}