<?php

namespace parldata;

define('MAX_POST_ITEMS', 100);

class ApiException extends \Exception {
    public $result;

    public function __construct($result, $extra_message = '') {
        parent::__construct($result->_error->message, $result->_error->code);
        $this->result = $result;

        if ($extra_message != '') {
            $this->message .= $extra_message;
        }
    }
}

class ValidationException extends ApiException {
    public $issues;
    public $items;

    public function __construct($method, $url, $result, $extra_message = '') {
        parent::__construct($result, $extra_message = '');

        $pre = "[$method] $url returned validation errors: ";
        if (isset($result->_issues)) {
            $this->issues = $result->_issues;
            $this->message = $pre . json_encode($this->issues);

        } else {
            $this->items = $result->_items;
            $this->message = $pre . json_encode($this->items);
        }
    }
}

class NetworkException extends ApiException {
    public function __construct($message) {
        $this->message = $message;
    }
}

class NotFoundException extends ApiException {
    public function __construct($result, $url) {
        parent::__construct($result);
        $this->message = "Not Found: " . $url;

        $this->url = $url;
    }
}


class API {
    private $endpoint;
    private $user;
    private $password;
    private $useragent;
    private $changes = array();

    public function __construct($endpoint, $user, $password, $useragent = 'ParlDataPHPApi/1.0') {
        $this->endpoint = $endpoint . '/';
        $this->user = $user;
        $this->password = $password;
        $this->useragent = $useragent;
        $this->changes = array();
    }

    public function create($type, $data) {
        if (empty($data)) {
            trigger_error ( "Tried to create empty $type", E_USER_NOTICE);
            return;
        }

        // chunk big inserts
        if (!is_array_assoc($data) && count($data) > MAX_POST_ITEMS) {
            $ids = array();

            for($offset = 0; $offset < count($data); $offset += MAX_POST_ITEMS) {
                $_ids = $this->create($type, array_slice($data, $offset, MAX_POST_ITEMS));
                $ids = array_merge($ids, $_ids);
            }

            return $ids;
        }

        if (!is_array_assoc($data)) {
            // creating multiple instances

            $this->debug("[CREATE] " . $type);
            foreach($data as $datum) {
                $this->debug("\t" . json_encode($datum));
            };

            $ids = $this->_post($this->endpoint . $type, $data);
            if (!is_array($ids)) {
                $ids = array($ids);
            }

            // save changes
            foreach($ids as $id) {
                if ($type != 'logs') {
                    array_push($this->changes, array(
                        'op' => 'CREATE',
                        'type' => $type,
                        'id' => $id
                    ));
                }
            }

            return $ids;
        } else {
            $this->debug("[CREATE] " . $type . " " . json_encode($data));

            $id = $this->_post($this->endpoint . $type, $data);
            if ($type != 'logs') {
                array_push($this->changes, array(
                    'op' => 'CREATE',
                    'type' => $type,
                    'id' => $id
                ));
            }

            return $id;
        }
    }

    public function updateIfNeeded($type, $id, $data, $data_old) {
        // convert to assoc array
        $data_old = json_decode(json_encode($data_old), true);

        // delete metadata
        unset($data_old['updated_at']);
        unset($data_old['created_at']);
        unset($data_old['_links']);

        $new_data = array_diff_assocr($data, $data_old);
        $previous_data = array_diff_assocr($data_old, $data); // won't be deleted because we are patching

        if ($new_data) {
            $this->debug("[UPDATE] changing data $type " . $data['id'] . ": NEW " . json_encode($new_data) . "; PREVIOUS: " . json_encode($previous_data));
            $this->update($type, $id, $data);
        } else {
            $this->debug("[UPDATE] skipping $type " . $data['id'] . " as no changes has been detected.");
        }
    }

    public function createOrUpdate($type, $data) {
        $this->createOr('update', $type, $data);
    }
    public function createOrSkip($type, $data) {
        $this->createOr('skip', $type, $data);
    }

    protected function createOr($or_what, $type, $data) {
        if ($or_what != 'update' and $or_what != 'skip') {
            throw new \InvalidArgumentException("First param should be 'update' or 'skip'.");
        }

        if (is_array_assoc($data)) {
            // individual object
            try {
                $obj = $this->get($type, $data['id']);
            } catch (NotFoundException $ex) {
                return $this->create($type, $data);
            }

            if ($or_what == 'update') {
                updateIfNeeded($type, $data['id'], $data, $obj);
            }

            return;
        }

        // split existing and non-existing
        $new_ids = $new_ids_backup = array_map(function ($s) {
            return $s['id'];
        }, $data);

        $existing = array();
        // chunk queries
        $query_params_limit = 25; // don't change, API may limit it
        for($offset = 0; $offset < count($new_ids); $offset += $query_params_limit) {
            $ids_to_get = array_slice($new_ids, $offset, $query_params_limit);
            $items = $this->find($type, array('max_results' => $query_params_limit, 'where' => array('id' => array('$in' => $ids_to_get))))->_items;
            foreach ($items as $s) {
                $existing[$s->id] = $s;
            }
        }

        $to_create = array();
        foreach($data as $o) {
            if (isset($existing[$o['id']])) {
                if ($or_what == 'update') {
                    $this->updateIfNeeded($type, $o['id'], $o, $existing[$o['id']]);
                }
            } else {
                array_push($to_create, $o);
            }
        }
        $data = $to_create;

        // chunk big inserts
        if (!is_array_assoc($data) && count($data) > MAX_POST_ITEMS) {
            $ids = array();

            for($offset = 0; $offset < count($data); $offset += MAX_POST_ITEMS) {
                $_ids = $this->create($type, array_slice($data, $offset, MAX_POST_ITEMS));
                $ids = array_merge($ids, $_ids);
            }

            //return $ids;
            return;
        }

        //return
        if (!empty($data)) {
            $this->create($type, $data);
        }
    }

    public function get($type, $id, $options = array()) {
        $this->debug("   [GET] " . $type . " " . $id . ' ' . json_encode($options));

        return $this->_get($this->endpoint . $type . '/' . $id, $options);
    }

    public function getOrCreate($type, $where, $object) {
        $objects = $this->find($type, array('where' => $where))->_items;
        if (count($objects) > 1) {
            throw new \Exception("Expected one object, but got " . count($objects) . " using where=" . json_encode($where));
        }
        if (count($objects) == 1) {
            return $objects[0];
        }

        $id = $this->create($type, $object);
        $object['id'] = $id;

        return (object) $object;
    }

    public function delete($type, $id) {
        $this->debug("[DELETE] " . $type . " " . $id);

        $this->_post($this->endpoint . $type . '/' . $id, array(), 'DELETE');
    }

    public function update($type, $id, $data, $replaceObject = false) {
        // TODO if data in array show in consecutive rows
        $this->debug("[UPDATE] " . $type . " " . $id . ($replaceObject ? ' PUT ' : ' PATCH ') . json_encode($data));

        if (isset($data['id'])) {
            if ($data['id'] == $id) {
                unset($data['id']);
            } else {
                throw new \Exception("Are you trying to update $type $id or " . $data['id'] . "?");
            }
        }

        return $this->_post($this->endpoint . $type . '/' . $id, $data, $replaceObject ? 'PUT' : 'PATCH');
    }

    public function find($type, $options = array()) {
        // TODO show actual url - move debug to _get
        $this->debug("  [FIND] " . $type . (empty($options) ? '' : ' ' . json_encode($options)));

        $fetch_all = has_key($options, 'all') ? $options['all'] : false;
        if ($fetch_all) {
            unset($options['all']);
            unset($options['page']);
            $options['max_results'] = 50;
        }

        $result = $this->_get($this->endpoint . $type, $options);

        if ($fetch_all) {
            $part = $result;
            while (isset($part->_links->next)) {
                $part = $this->_get($this->endpoint . $part->_links->next->href);
                $result->_items = array_merge($result->_items, $part->_items);
            }

            unset($result->_meta->page);
            unset($result->_links->next);
            $result->_meta->max_results = $result->_meta->total;
        }

        return $result;
    }

    // ==================================================

    public function updatePerson($id, $person, $replaceObject = false) {
        $this->update('people', $id, $person, $replaceObject);
    }

    public function findPeople($options = array()) {
        return $this->find('people', $options);
    }

    public function createPerson($person) {
        return $this->create('people', $person);
    }

    public function createMembership($membership) {
        return $this->create('memberships', $membership);
    }

    public function createLog($log) {
        $this->create('logs', $log);
    }

    public function createOrganization($org) {
        return $this->create('organizations', $org);
    }

    public function updateOrganization($id, $organization, $replaceObject = false) {
        $this->update('organizations', $id, $organization, $replaceObject);
    }

    public function getOrganization($id, $options = array()) {
        return $this->get('organizations', $id, $options);
    }

    public function getPerson($id, $options = array()) {
        return $this->get('people', $id, $options);
    }

    public function getMembership($id, $options = array()) {
        return $this->get('memberships', $id, $options);
    }

    // ====================================================


    /**
     * Send a POST requst using cURL
     * @param string $url to request
     * @param array $data values to send
     * @param array $options for cURL
     * @return string
     */
    private function _post($url, $data = array(), $method = 'POST') {
        if (defined('DRY_RUN') and DRY_RUN) {
            echo "  Skipping $method to $url\n";
            return;
        }

        $options = array(
            //CURLOPT_POST => 1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array("Content-type: application/json"),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_USERPWD => $this->user . ":" . $this->password,
            CURLOPT_SSL_VERIFYPEER => defined('SKIP_CRT_VALIDATION') ? !SKIP_CRT_VALIDATION : true,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FAILONERROR => false, // so can catch verbose error message from API
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => $this->useragent,
        );

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // TODO is it the right place to ask for it?
        if (($result = curl_exec($ch)) === false || $http_code >= 300) {
            $curl_error = curl_error($ch);
            curl_close($ch);

            error_log("[$method " . $url . "] " . json_encode($data));
            throw new NetworkException($curl_error);
        }
        curl_close($ch);

        // Check for validation errors
        $result = json_decode($result);

        if ($result != null and $result->_status == 'ERR') {
            if (isset($result->_issues) || isset($result->_items)) {
                throw new ValidationException($method, $url, $result);

            } else {
                throw new ApiException($result, " while $method $url");
            }
        }

        if (isset($result->id)) {
            return $result->id;

        } else if(isset($result->_items)) {
            return array_map(function($item) {
                return $item->id;
            }, $result->_items);
        }
        return;
    }

    /**
     * Send a GET requst using cURL
     * @param string $url to request
     * @param array $get values to send
     * @param array $options for cURL
     * @return string
     */
    private function _get($url, array $options = array()) {
        $query = '';
        if (!empty($options)) {
            $query = '?';

            $i = 0;
            foreach($options as $opt => $value) {
                $query .= ($i > 0 ? '&' : '') . $opt . '=' . ($opt == 'sort' ? $value : json_encode($value));
                $i++;
            }
        }

        $url .= $query;

        // spaces in strings causes er400; one can use urlencode to be even more safe
        $url = str_replace(' ', '%20', $url);

        $defaults = array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 0,
            CURLOPT_SSL_VERIFYPEER => defined('SKIP_CRT_VALIDATION') ? !SKIP_CRT_VALIDATION : true,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_TIMEOUT => 4
        );

        $ch = curl_init();
        curl_setopt_array($ch, $defaults);
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!$result) {
            $curl_error = curl_error($ch);
            curl_close($ch);

            error_log("[GET] " . $url);
            if ($http_code == 404) {
                throw new NotFoundException('', $url);
            }
            throw new NetworkException($curl_error);
        }
        curl_close($ch);

        $result = json_decode($result);

        if (isset($result->_status) and $result->_status == 'ERR') {
            if ($result->_error->code == 404) {
                throw new NotFoundException($result, $url);
            }
            throw new ApiException($result, " while GET $url");
        }

        return $result;
    }


    public function doRequest($id, $test = false) {
        $data = $this->findById($id);
        //set connect config
        $direct = $data['QueleToSend']['direct'];
        $getSet = Configure::read('api_server');
        $username = $getSet[$direct]['username'];
        $password = $getSet[$direct]['password'];
        $baseUrl = $getSet[$direct]['base_url'];

        $HttpSocket = new HttpSocket(array(
            'ssl_allow_self_signed' => true
        ));
        //  $HttpSocket->configAuth('Basic', 'scraper', 'ngaA(f77');
        $HttpSocket->configAuth('Basic', $username, $password);
        $request = array(
            'header' => array('Content-Type' => 'application/json'),
            'raw' => null,
        );
        $postSend = unserialize($data['QueleToSend']['data']);
        $putSend = $postSend;
//        pr($putSend);
//        pr(json_encode($postSend));
        unset($putSend['id']);
        $delete = false;
        $combine = array(
            'url_post' => $baseUrl . $data['QueleToSend']['type'],
            'post_send' => json_encode($postSend),
            'url_put' => $baseUrl . $data['QueleToSend']['type'] . '/' . $data['QueleToSend']['uid'],
            'put_send' => json_encode($putSend),
//            'delete' => 'https://api.parldata.eu/rs/skupstina/' . $data['QueleToSend']['type'],
        );
        usleep(300);
        $results = $HttpSocket->post($combine['url_post'], $combine['post_send'], $request);
        if ($test) {
            pr($results);
        }
        $result = json_decode($results->body);
        $status['status'] = false;
        $status['code'] = $results->code;
        if ($status['code'] == 500) {
            sleep(5);
            return $status;
        }
        if ($result->_status == 'ERR') {
            $results = null;
            $results = $HttpSocket->put($combine['url_put'], $combine['put_send'], $request);
            $status['code'] = $results->code;
            if ($status['code'] == 500) {
                sleep(5);
                return $status;
            }
            if ($test) {
                pr($results);
            }
            $result = json_decode($results->body);
            if ($result->_status == 'OK') {
                $status['status'] = true;
            }
        } elseif ($result->_status == 'OK') {
            $status['status'] = true;
        }
        if ($test) {
//            pr(array($status, $data, $results, $postSend));
        } else {
            return $status;
        }
        // return array($status, $data, $results, $postSend);
    }

    /**
     * Rollback mechanism
     *
     * Doesn't handle DELETE, PUT and PATCH
     * Handles POST
     */
    public function rollback() {
        $this->debug("Rolling back changes..");

        try {
            while(!empty($this->changes)) {
                $change = $this->changes[0];

                switch ($change['op']) {
                    case 'CREATE':
                        $this->delete($change['type'], $change['id']);
                }

                array_shift($this->changes);
            }

        } catch (\Exception $ex) {
            error_log("Couldn't rollback following changes: \n" . var_export($this->changes, true));
            throw new \Exception("Caught exception while rolling back. DB is in non-consistent state.", 0, $ex);
        }

        $this->changes = array();
        $this->debug("Changes rolled back.");
    }

    public function commit() {
        // there is one rollback point
        $this->changes = array();
    }

    private function debug($msg) {
        if (defined('DEBUG') and DEBUG) {
            echo $msg . "\n";
        }
    }
}

function array_diff_assocr($aArray1, $aArray2) {
    $aReturn = array();

    foreach ($aArray1 as $mKey => $mValue) {
        if (array_key_exists($mKey, $aArray2)) {
            if (is_array($mValue)) {
                $aRecursiveDiff = array_diff_assocr($mValue, $aArray2[$mKey]);
                if (count($aRecursiveDiff)) { $aReturn[$mKey] = $aRecursiveDiff; }
            } else {
                if ($mValue != $aArray2[$mKey]) {
                    $aReturn[$mKey] = $mValue;
                }
            }
        } else {
            $aReturn[$mKey] = $mValue;
        }
    }
    return $aReturn;
}

function is_array_assoc($arr)
{
    return array_keys($arr) !== range(0, count($arr) - 1);
}