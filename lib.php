<?php
// This file is part of Moodle - http://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * @package local
 * @subpackage userinfosync
 * @copyright 2013 Ivan Šakić
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/lib/filelib.php');

/**
 * Class userinfosync_crypt
*/
class userinfosync_crypt {
    private $privatekeyres;
    private $publickeyres;

    public function __construct() {
        $keypair = unserialize(get_config('userinfosync', 'resource'));
        if (!$keypair) {
            $resource = openssl_pkey_new();
            $keypair = array(
                    'private' => $this->get_private_key($resource),
                    'public' => $this->get_public_key($resource),
            );
            set_config('resource', serialize($keypair), 'userinfosync');
        } else {
            $this->privatekeyres = openssl_pkey_get_private($keypair['private']);
            $this->publickeyres = openssl_pkey_get_public($keypair['public']);
        }
    }

    public function get_private_key($resource = null) {
        if (!$resource) {
            $resource = $this->privatekeyres;
        }
        $privatekey = false;
        openssl_pkey_export($resource, $privatekey);
        return $privatekey;
    }

    public function get_public_key($resource = null) {
        if (!$resource) {
            $resource = $this->publickeyres;
        }
        $details = openssl_pkey_get_details($resource);
        return isset($details['key']) ? $details['key'] : false;
    }

    public function verify_peer($url) {
        $trustedpeers = preg_split('/[\s\r\n]+/', get_config('moodle', 'userinfosync_trustedpeers'));
        return array_search($url, $trustedpeers) !== false;
    }

    public function get_public_key_for_url($url) {
        global $CFG;
        $myurl = $CFG->wwwroot;
        $curl = new curl();
        if (!$remotepublickey = $curl->post("$url/local/userinfosync/requestkey.php", array('url' => base64_encode($myurl)))) {
            $this->report_error("Could not retrieve remote peer public key!");
        }
        if (!$remotepublickeyres = openssl_pkey_get_public($remotepublickey)) {
            $this->report_error("Retrieved remote peer public key is corrupted!");
        }

        return $remotepublickeyres;
    }

    /**
     * @param  $payload             mixed   a string to be sealed
     * @param  $remotepublickeyres  mixed   resource handle of the peer's public key
     * @return string                       sealed message
     */
    public function encrypt_message($payload, $remotepublickeyres) {
        $this->clear_openssl_errors();

        if (!openssl_sign($payload, $signature, $this->privatekeyres)) {
            $this->report_error("Could not create signature!");
        }
        $encodedsignature = base64_encode($signature);
        if (!openssl_seal($payload, $encryptedpayload, $encryptedkey, array($remotepublickeyres))) {
            $this->report_error("Could not encrypt the symmetric key!");
        }
        $encodedencryptedpayload = base64_encode($encryptedpayload);
        $encodedkey = base64_encode($encryptedkey[0]);
        $message = base64_encode(serialize(array($encodedsignature, $encodedkey, $encodedencryptedpayload)));
        return $message;
    }

    /**
     * @param  $message             mixed   a message to be opened
     * @param  $remotepublickeyres  mixed   resource handle of the peer's public key
     * @return string                       message payload
     */
    public function decrypt_message($message, $remotepublickeyres) {
        $this->clear_openssl_errors();

        list($encodedsignature, $encodedencryptedkey, $encodedencryptedpayload) = unserialize(base64_decode($message));
        $signature = base64_decode($encodedsignature);
        $encryptedkey = base64_decode($encodedencryptedkey);
        $encryptedpayload = base64_decode($encodedencryptedpayload);

        if (!openssl_open($encryptedpayload, $payload, $encryptedkey, $this->privatekeyres)) {
            $this->report_error("Could open the payload!");
        }

        if (!openssl_verify($payload, $signature, $remotepublickeyres)) {
            $this->report_error("Could not verify signature!");
        }

        return $payload;
    }

    private function report_error($error_message) {
        debugging($error_message . $this->format_openssl_errors());
    }

    private function clear_openssl_errors() {
        while ($msg = openssl_error_string());
    }

    private function format_openssl_errors() {
        $message = '';
        while ($msg = openssl_error_string()) {
            $message .= ' <br/ >' . $msg;
        }
        return $message;
    }
}
/**
 *
 *
 *
 */
class userinfosync {

    /**
     *
     * @param array $usernames
     * @param array $fieldnames
     * @return multitype:Ambigous <multitype:, multitype:mixed >
     */
    public static function get_local_user_info(array $usernames, array $fieldnames) {
        global $DB;
        list($insql, $params) = $DB->get_in_or_equal($usernames, SQL_PARAMS_NAMED);
        $query = "SELECT u.username, u.id
        FROM {user} u
        WHERE u.username $insql";
        $usernames = $DB->get_records_sql_menu($query, $params);

        $data = array();
        list($insql, $params) = $DB->get_in_or_equal($fieldnames, SQL_PARAMS_NAMED);
        foreach ($usernames as $username => $userid) {
            $query = "SELECT f.shortname, d.data
            FROM {user_info_field} f
            INNER JOIN {user_info_data} d ON f.id = d.fieldid
            WHERE d.userid = :userid
            AND f.shortname $insql";
            $userinfo = $DB->get_records_sql_menu($query, $params + array('userid' => $userid));
            $data[$username] = $userinfo;
        }

        return $data;
    }
    /**
     * Function to be called to initiate the profile field syncing.
     *
     * @param array $userids userids of the profiles to sync
     */
    public static function update_user_fields($userids) {
        global $DB;

        if (empty($userids) || get_config('moodle', 'userinfosync_hosttype') === 'idp') {
            return;
        }

        list($insql, $params) = $DB->get_in_or_equal($userids);
        $query = "SELECT u.id, u.username, u.mnethostid
        FROM {user} u
        INNER JOIN {mnet_host} h ON u.mnethostid = h.id
        WHERE u.id $insql
        AND u.auth LIKE 'mnet'";
        $results = $DB->get_records_sql($query, $params);

        $trustedpeers = explode("\n", get_config('moodle', 'userinfosync_trustedpeers'));

        $trustedhostids = array();
        foreach($trustedpeers as $peer){
            $select = "wwwroot = '$peer'";
            $trustedhostids = $DB->get_fieldset_select('mnet_host', 'id', $select);
        }

        $hostidtousers = array();
        foreach ($results as $result) {
            if(in_array($result->mnethostid, $trustedhostids)){
                if (!isset($hostidtousers[$result->mnethostid])) {
                    $hostidtousers[$result->mnethostid] = array();
                }
                $hostidtousers[$result->mnethostid][] = $result->username;
            }
        }

        $fieldnames = $DB->get_records_select_menu('user_info_field', '1', array('id', 'shortname'));

        foreach ($hostidtousers as $hostid => $usernames) {
            $url = $DB->get_field('mnet_host', 'wwwroot', array('id' => $hostid));
            $userdata = self::request_user_info($url, $usernames, $fieldnames);
            foreach ($userdata as $username => $fields) {
                $userid = $DB->get_field('user', 'id', array('username' => $username, 'auth' => 'mnet', 'mnethostid' => $hostid));
                foreach ($fields as $fieldname => $data) {
                    $fieldid = array_search($fieldname, $fieldnames);
                    if ($DB->record_exists('user_info_data', array('userid' => $userid, 'fieldid' => $fieldid))) {
                        $DB->set_field('user_info_data', 'data', $data, array('userid' => $userid, 'fieldid' => $fieldid));
                    } else {
                        $record = array(
                                'userid' => $userid,
                                'fieldid' => $fieldid,
                                'data' => $data,
                        );
                        $DB->insert_record('user_info_data', (object) $record);
                    }
                }
            }
        }
    }

    public static function request_user_info($url, $usernames, $fieldnames) {
        global $CFG;

        if (!extension_loaded('openssl') ||
                !extension_loaded('curl')) {
            return false;
        }

        $crypt = new userinfosync_crypt();
        $remotepublickeyres = $crypt->get_public_key_for_url($url);
        $payload = serialize(array($usernames, $fieldnames));
        $request = $crypt->encrypt_message($payload, $remotepublickeyres);
        $curl = new curl();

        if (!$response = $curl->post("$url/local/userinfosync/server.php", array('url' => base64_encode($CFG->wwwroot), 'request' => $request))) {
            return false;
        }

        $decryptedresponse = $crypt->decrypt_message($response, $remotepublickeyres);
        return unserialize($decryptedresponse);
    }
}

// Cronjob
/**
 * Check if the site is identity provider or subscriber
 * If subscriber get date of last cron execution
 * and sync all users where lastlogin is more recent than last cronjob
 *
 */
function local_userinfosync_cron(){
    global $DB;

    if (get_config('moodle', 'userinfosync_hosttype') === 'idp') {
        return;
    }
    $sql = '
        SELECT MAX(lastcron)
        FROM {modules}
        WHERE lastcron < ( SELECT MAX(lastcron) FROM {modules} )';
    $lastcron = $DB->get_field_sql($sql);
    $select = 'lastaccess >= '.$lastcron;
    $params = array('auth' => 'mnet');
    $userids = $DB->get_fieldset_select('user', 'id', $select, $params);
    userinfosync::update_user_fields($userids);
}
