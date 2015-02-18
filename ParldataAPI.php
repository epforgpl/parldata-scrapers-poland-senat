<?php

namespace parldata;

class ApiException extends \Exception {
    public function __construct($result) {
        parent::__construct($result->_error->message, $result->_error->code);
        $this->result = $result;
    }
}

class ValidationException extends ApiException {
    public function __construct($result) {
        parent::__construct($result);

        $this->issues = $result->_issues;
        $this->message = "Validation errors: " . json_encode($this->issues);
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
    public function __construct($endpoint, $user, $password, $useragent = 'ParlDataPHPApi/1.0') {
        $this->endpoint = $endpoint . '/';
        $this->user = $user;
        $this->password = $password;
        $this->useragent = $useragent;
    }

    public function create($type, $data) {
        $this->debug("[CREATE] " . $type . " " . json_encode($data));

        $this->_post($this->endpoint . $type, $data);
    }

    public function get($type, $id, $options = array()) {
        $this->debug("   [GET] " . $type . " " . $id . ' ' . json_encode($options));

        return $this->_get($this->endpoint . $type . '/' . $id, $options);
    }

    public function delete($type, $id) {
        $this->debug("[DELETE] " . $type . " " . $id);
    }

    public function update($type, $id, $data, $replaceObject = false) {
        $this->debug("[UPDATE] " . $type . " " . $id . ($replaceObject ? ' PUT ' : ' PATCH ') . json_encode($data));

        $this->_post($this->endpoint . $type . '/' . $id, $data, $replaceObject ? 'PUT' : 'PATCH');
    }

    public function find($type, $options = array()) {
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
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_USERAGENT => $this->useragent,
        );

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        if (!$result = curl_exec($ch)) {
            $curl_error = curl_error($ch);
            curl_close($ch);

            error_log("[POST " . $url . "] " . json_encode($data));
            throw new NetworkException($curl_error);
        }
        curl_close($ch);

        // Check for validation errors
        $result = json_decode($result);

        if ($result->_status == 'ERR') {
            if (isset($result->_issues)) {
                throw new ValidationException($result);

            } else {
                throw new ApiException($result);
            }
        }

        if ($result->id) {
            return $result->id;
        }
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
                $query .= ($i > 0 ? '&' : '') . $opt . '=' . json_encode($value);
                $i++;
            }
        }

        $url .= $query;

        $defaults = array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 0,
            CURLOPT_SSL_VERIFYPEER => defined('SKIP_CRT_VALIDATION') ? !SKIP_CRT_VALIDATION : true,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_TIMEOUT => 4
        );

        $ch = curl_init();
        curl_setopt_array($ch, $defaults);
        if (!$result = curl_exec($ch)) {
            $curl_error = curl_error($ch);
            curl_close($ch);

            error_log("[GET " . $url . "] " . json_encode($get));
            throw new NetworkException($curl_error);
        }
        curl_close($ch);

        $result = json_decode($result);

        if (isset($result->_status) and $result->_status == 'ERR') {
            if ($result->_error->code == 404) {
                throw new NotFoundException($result, $url);
            }
            throw new ApiException($result);
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

    private function debug($msg) {
        if (defined('DEBUG') and DEBUG) {
            echo $msg . "\n";
        }
    }
}