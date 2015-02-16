<?php

require_once('config.php');
require('ParldataAPI.php');

require(join(DIRECTORY_SEPARATOR, array(SENAT_PARSER_DIR, 'lib', 'SenatParser.class.php')));

# An hour should be enough
set_time_limit(3600);

$updater = new SenatUpdater();

$updater->run(array_slice($argv, 1));

class SenatUpdater {
    function __construct() {
        $this->api = new parldata\API(API_ENDPOINT, SCRAPER_USER, SCRAPER_PASSWORD, 'ParlDataPHPApi/1.0 SenatParser/1.2');
        $this->parser = new SenatParser();
    }

    private function mapPerson($web) {
        $person = array(
            'id' => $web['id'],
            'name' => $web['name'],
            'image' => $web['photo'],
            'sources' => array(array('url' => $web['url']))
        );

        $names = preg_split("/\s+/", $web['name']);
        if (count($names) != 2) {
            $this->warn("Multi-part name: " . $web['name']);
        }
        $person['family_name'] = array_pop($names);
        $person['given_name'] = join(' ', $names);

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


//        'cadencies'=>$this->_senatorExtractCadencies($html),
//            'clubs'=>$this->_senatorExtractClubs($html),
//            'commissions'=>$this->_senatorExtractCommissions($html),
//            'parliamentary_assemblies'=>$this->_senatorExtractParlimentaryAssemblies($html),
//            'senat_assemblies'=>$this->_senatorExtractSenatAssemblies($html),

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
            $this->{$job}();

            $log['status'] = 'finished';
            $this->api->createLog($log);

        } catch (Exception $ex) {
            $log['params'] = array_merge(has_key($log, 'params') ? $log['params'] : array(), array(
                'error_type' => get_class($ex),
                'error_message' => (string)$ex
            ));
            $log['status'] = 'failed';
            $this->api->createLog($log);

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
                    $person = self::mapPerson($data['web']);
                    $this->api->createPerson($person);
                }
            } else if (has_key($data, 'api')) {
                // TODO delete Senat - senator membership
            }
        }
    }

    function updateSenators() {
        foreach ($this->api->findPeople(array('all' => true))->_items as $a) {
            $web = $this->parser->updateSenatorInfo($a->id);
            $person = self::mapPersonInfo($web);
            $this->api->updatePerson($a->id, $person, false);
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
}

function has_key($Arr, $key) {
    return isset($Arr[$key]) || array_key_exists($key, $Arr);
}