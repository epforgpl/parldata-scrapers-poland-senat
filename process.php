<?php

require_once('config.php');
require('ParldataAPI.php');

require(SENAT_PARSER_DIR . 'SenatParser.php');

use parldata\parsers\ParserException;

# An hour should be enough
set_time_limit(3600);

$updater = new SenatUpdater();

$updater->run(array_slice($argv, 1));

class SenatUpdater {
    private $org_classification_klub = 'klub';
    private $org_classification_komisja = 'komisja';

    function __construct() {
        $this->api = new parldata\API(API_ENDPOINT, SCRAPER_USER, SCRAPER_PASSWORD, 'ParlDataPHPApi/1.0 SenatParser/1.2');
        $this->parser = new parldata\parsers\Senat();
    }

    private function mapPerson($web) {
        $person = array(
            'id' => $web['id'],
            'name' => $web['name'],
            'image' => $web['photo'],
            'sources' => array(array('url' => $web['url']))
        );

        $names = preg_split("/\s+/", $web['name']);
        if (count($names) > 3) {
            $this->warn("Multi-part name: " . $web['name']);
        }
        $person['family_name'] = array_pop($names);
        $person['given_name'] = array_shift($names);

        if (!empty($names)) {
            $person['additional_name'] = $names[0];
        }

        return $person;
    }

    private function mapPersonInfo($web) {
        // TODO filter biography & birth_date for emptiness
        $person = array(
            'national_identity' => 'Polish',
            'biography' => $web['bio_note'],
            'birth_date' => $web['birth_date']
        );

        $contact_details = array();
        if ($web['email']) {
            array_push($contact_details, array(
                'label' => 'E-mail',
                'type' => 'email',
                'value' => $web['email']
            ));
        }
        if ($web['www']) {
            array_push($contact_details, array(
                'label' => 'Website',
                'type' => 'url',
                'value' => $web['www']
            ));
        }

        if (!empty($contact_details)) {
            $person['contact_details'] = $contact_details;
        }

        return $person;
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

        $log = array(
            'label' => $job,
            'status' => 'running',
            //'file' => null // TODO
        );
        // 'params' => null,

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

                $this->current_chamber = 'chamber_' . $terms[0]['id'];
                $this->ensureChambersCreated($terms);

            } else {
                // TODO maybe automatic term_id increment and setting dates
                throw new Exception('New term of office has arrived! Please fill info about the current');
            }

            $this->{$job}();

            $log['status'] = 'finished';
            $this->api->createLog($log);

        } catch (Exception $ex) {
            $log['params'] = array_merge(has_key($log, 'params') ? $log['params'] : array(), array(
                'error_type' => get_class($ex),
                'error_message' => $ex->getMessage()
            ));
            $log['status'] = 'failed';
            $this->api->createLog($log);

            // TODO error log without throwing?

            throw $ex;
        }
    }

    function updateSenatorsList() {
        foreach ($this->parser->updateSenatorsList() as $w) {
            $list[$w['id']]['web'] = $w;
            // TODO notes = "Mandat wygasł w związku z wyborem na posła do Parlamentu Europejskiego	25.05.2014 r."
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
    }

    function updateSenators() {
        foreach ($this->api->findPeople(array('all' => true))->_items as $a) {
            $id_senator = $a->id;

            // TODO Nieznana data urodzin:
            $person_data = $this->parser->updateSenatorInfo($id_senator);
            $person = self::mapPersonInfo($person_data);
            $this->api->updatePerson($id_senator, $person);

            // Download and map all memberships
            $person = $this->api->getPerson($id_senator, array('embed' => array('memberships.person')));

            $existing_memberships = array();
            if (isset($person->memberships)) {
                foreach($person->memberships as $m) {
                    $m->_found = false;
                    $existing_memberships[$m->id] = $m;
                }
            }

            // Kadencje - terms of office
            foreach($person_data['cadencies'] as $wterm) {
                $id_chamber = 'chamber_' . strtoupper(($wterm));

                // memberships
                $membership_id = $id_chamber . '-' .$id_senator;

                if (has_key($existing_memberships, $membership_id)) {
                    $existing_memberships[$membership_id]->_found = true;

                    if (!empty($person_data['mandate_end_date'])
                        and empty($existing_memberships[$membership_id]->end_date)
                        and $id_chamber == $this->current_chamber) {

                        $this->api->update('memberships', $membership_id,
                            array('end_date' => $person_data['mandate_end_date']));
                    }

                } else {
                    // create membership
                    $membership = (object) array(
                        'id' => $membership_id,
                        'person_id' => $id_senator,
                        'organization_id' => $id_chamber,
                    );
                    if (defined('FIRST_PASS') and !FIRST_PASS) {
                        // we can assume that if there's new connection it happened today
                        $membership->start_date = $this->today();
                    }

                    $this->api->createMembership($membership);
                }
            }

            // Kluby - senat clubs
            foreach ($person_data['clubs'] as $club) {
                $id_klub = $this->org_classification_klub . '_' . $club['id'];

                // check if club exists
                try {
                    // TODO optimize, get 'get' out of here
                    $this->api->getOrganization($id_klub);

                } catch(parldata\NotFoundException $ex) {
                    $org = array(
                        'id' => $id_klub,
                        'name' => $club['name'],
                        'classification' => $this->org_classification_klub,
                        'sources' => array(array(
                            'url' => $club['url']
                        ))
                    );
                    $this->api->createOrganization($org);
                }

                // memberships
                $membership_id = $id_klub . '-' .$id_senator;

                if (has_key($existing_memberships, $membership_id)) {
                    $existing_memberships[$membership_id]->_found = true;

                } else {
                    // create membership
                    $membership = (object) array(
                        'id' => $membership_id,
                        'person_id' => $id_senator,
                        'organization_id' => $id_klub,
                    );
                    if (defined('FIRST_PASS') and !FIRST_PASS) {
                        // we can assume that if there's new connection it happened today
                        $membership->start_date = $this->today();
                    }

                    $this->api->createMembership($membership);
                }
            }

//        'cadencies'=>$this->_senatorExtractCadencies($html),
//            'commissions'=>$this->_senatorExtractCommissions($html),
//            'senat_assemblies'=>$this->_senatorExtractSenatAssemblies($html),

            // delete old memberships
            foreach($existing_memberships as $m) {
                if (! $m->_found) {
                    $this->api->updateMembership($m->id, array(
                        'end_date' => $this->today()
                    ));
                }
            }
        }
    }

    private function debug($msg) {
        if (defined('DEBUG') and DEBUG) {
            echo $msg . "\n";
        }
    }

    private function warn($msg) {
        echo "[WARNING] " . $msg . "\n";
        // TODO log
    }

    private function senator_url($id) {
        return 'http://senat.gov.pl/sklad/senatorowie/senator,' . $id . '.html';
    }

    private function today() {
        $date = new DateTime();
        return $date->format('Y-m-d');
    }

    private function ensureChambersCreated($terms) {
        $chambers = array();
        foreach($this->api->find('organizations', array('where' => array('classification' => 'chamber')))->_items as $chamber) {
            $chambers[$chamber->id] = $chamber;
        }

        foreach($terms as $term) {
            $chamber_id = 'chamber_' . $term['id'];
            if (!has_key($chambers, $chamber_id)
                or (isset($term['end_date']) and $chambers[$chamber_id]->dissolution_date != $term['end_date'])) {

                $org = array(
                    'id' => $chamber_id,
                    'name' => 'Senat - kadencja ' . $term['id'],
                    'classification' => 'chamber',
                    'founding_date' => $term['start_date'],
                    'sources' => array(array(
                        'url' => $term['source']
                    ))
                );
                if (has_key($term, 'end_date')) {
                    $org['dissolution_date'] = $term['end_date'];
                }

                $this->api->createOrganization($org);
            }
        }
    }
}

function has_key($Arr, $key) {
    return isset($Arr[$key]) || array_key_exists($key, $Arr);
}