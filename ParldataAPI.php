<?php

namespace parldata;

class ValidationException extends \Exception {
    public function __construct($issues) {
        $this->issues = $issues;
    }

    public function __toString() {
        return "ValidationException: " . json_encode($this->issues);
    }
}

class NetworkException extends \Exception {

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

    public function get($type, $id) {
        $this->debug("   [GET] " . $type . " " . $id);
    }

    public function delete($type, $id) {
        $this->debug("[DELETE] " . $type . " " . $id);
    }

    public function update($type, $id, $data, $replaceObject = true) {
        $this->debug("[UPDATE] " . $type . " " . $id . " " . json_encode($data));

        $this->_post($this->endpoint . $type . '/' . $id, $data, $replaceObject ? 'PUT' : 'PATCH');
    }

    public function find($type, $options = array()) {
        $this->debug("  [FIND] " . $type . (empty($options) ? '' : '?' . \http_build_query($options)));

        $fetch_all = has_key($options, 'all') ? $options['all'] : false;
        if ($fetch_all) {
            unset($options['all']);
            unset($options['page']);
            $options['max_results'] = 50;
        }

        $query = '';
        if (!empty($options)) {
            $query = '?' . \http_build_query($options);
        }

        $result = $this->_get($this->endpoint . $type . $query);

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

    public function updatePerson($id, $person, $replaceObject = true) {
        $this->update('people', $id, $person, $replaceObject);
    }

    public function findPeople($options = array()) {
        return $this->find('people', $options);
    }

    public function createPerson($person) {
        return $this->create('people', $person);
    }

    public function createLog($log) {
        $this->create('logs', $log);
    }

    // ====================================================


    /**
     * Send a POST requst using cURL
     * @param string $url to request
     * @param array $data values to send
     * @param array $options for cURL
     * @return string
     */
    private function _post($url, array $data = array(), $method = 'POST') {
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
            throw new ValidationException($result->_issues);
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
    private function _get($url, array $get = array(), array $options = array()) {
        $defaults = array(
            CURLOPT_URL => $url . (strpos($url, '?') === FALSE ? '?' : '') . http_build_query($get),
            CURLOPT_HEADER => 0,
            CURLOPT_SSL_VERIFYPEER => defined('SKIP_CRT_VALIDATION') ? !SKIP_CRT_VALIDATION : true,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_TIMEOUT => 4
        );

        $ch = curl_init();
        curl_setopt_array($ch, ($options + $defaults));
        if (!$result = curl_exec($ch)) {
            $curl_error = curl_error($ch);
            curl_close($ch);

            error_log("[GET " . $url . "] " . json_encode($get));
            throw new NetworkException($curl_error);
        }
        curl_close($ch);

        $result = json_decode($result);
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