<?php

class NagdashHelpers {

    static function print_tag($tag_name, $nagios_hostcount) {
        if (($nagios_hostcount) > 1) {
            return "<span class='tag tag_{$tag_name}'>{$tag_name}</span>";
        } else {
            return false;
        }
    }

    /**
     * Fetch JSON data from an HTTP endpoint
     *
     * Parameters
     *  $hostname - the hostname of the endpoint
     *  $port     - the port to connect to
     *  $protocol - the protocol used (http or https)
     *  $url      - the endpoint url on the host
     *
     *  Return an array of the form
     *  [ "errors" => true/false, "details" => "json_decoded data",
     *    "curl_stats" => "stats from the curl call"]
     */
    static function fetch_json($hostname,$port,$protocol,$url) {

        $ch = curl_init("$protocol://$hostname:$port$url");
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $json = curl_exec($ch);

        $info = curl_getinfo($ch);

        $ret = ["errors" => false];
        if (curl_errno($ch)) {
            $errmsg = "Attempt to hit API failed, sorry. ";
            $errmsg .= "Curl said: " . curl_error($ch);
            return ["errors" => true,
                    "details" => $errmsg ];

        } elseif ($info['http_code'] != 200) {
            $errmsg = "Attempt to hit API failed, sorry. ";
            $errmsg .= "Curl said: HTTP Status {$info['http_code']}";
            return ["errors" => true,
                    "details" => $errmsg ];
        } else {
            $ret["curl_stats"] = ["$hostname:$port" => curl_getinfo($ch)];
            $ret["details"] = json_decode($json, true);
        }

        curl_close($ch);
        return $ret;
    }


    static function deep_ksort(&$arr) {
        ksort($arr);
        foreach ($arr as &$a) {
            if (is_array($a) && !empty($a)) {
                NagdashHelpers::deep_ksort($a);
            }
        }
    }

    /**
     * stupid template rendering function. This basically just works around
     * the whole global variables thing and gives you a way to pass variables
     * to a PHP rendered template.
     *
     * Parameters:
     *   $template - path to the template to render (relative to callsite)
     *   $vars     - array of variables used for rendering
     *
     * Returns nothing but renders the template in place
     */
    static function render($template, $vars = []) {
        extract($vars);
        include $template;
    }

    /**
     * helper function to compare last state change
     *
     * Parameter:
     *   $a - first state
     *   $b - second state
     *
     * Returns -1, 0 or 1 depending on state comparison
     */
    static function cmp_last_state_change($a,$b) {
        if ($a['last_state_change'] == $b['last_state_change']) return 0;
        return ($a['last_state_change'] > $b['last_state_change']) ? -1 : 1;
    }

    /**
     * get the correct state data based on the api type
     *
     * Parameters:
     *  $hostname - hostname of the nagios instance
     *  $port     - port the nagios api instance is listening on
     *  $protocol - the protocol to use for the transport (http/s)
     *  $api_type - the type of API to use (nagiosapi, livestatus, ...)
     *
     * Returns an array of [$state, $mapping, $curl_stats]
     */
    static function fetch_state($hostname, $port, $protocol, $api_type) {

        switch ($api_type) {
        case "livestatus":
            $nagios_api = new NagiosLivestatus($hostname, $port, $protocol);
            $ret = $nagios_api->getState();
            $state = $ret["details"];
            $curl_stats = $ret["curl_stats"];
            $mapping = $nagios_api->getColumnMapping();
            break;
        case "nagios-api":
            $nagios_api = new NagiosAPI($hostname, $port, $protocol);
            $ret = $nagios_api->getState();
            if ($ret["errors"] == true) {
                $state = $ret["details"];
            } else {
                $state = $ret["details"]["content"];
            }
            $curl_stats = $ret["curl_stats"];
            $mapping = $nagios_api->getColumnMapping();
            break;
        }

        return [$state, $mapping, $curl_stats];
    }

    /**
     * get the host data from all nagios instances
     *
     * Parameters:
     *  $nagios_hosts   - nagios hosts configuration array
     *  $unwanted_hosts - list of unwanted tags for the user
     *  $api_type       - API type to use
     *
     *  Returns [$state, $api_cols, $errors, $curl_stats]
     */
    static function get_nagios_host_data($nagios_hosts, $unwanted_hosts, $api_type) {
        $state  = [];
        $errors = [];
        $curl_stats = [];
        $api_cols = [];
        foreach ($nagios_hosts as $host) {
            // Check if the host has been disabled locally
            if (!in_array($host['tag'], $unwanted_hosts)) {
                list($host_state, $api_cols, $local_curl_stats) = NagdashHelpers::fetch_state($host['hostname'],
                    $host['port'], $host['protocol'], $api_type);
                $curl_stats = array_merge($curl_stats, $local_curl_stats);
                if (is_string($host_state)) {
                    $errors[] = "Could not connect to API on host {$host['hostname']}, port {$host['port']}: {$host_state}";
                } else {
                    foreach ($host_state as $this_host => $null) {
                        $host_state[$this_host]['tag'] = $host['tag'];
                    }
                    $state += (array) $host_state;
                }
            }
        }

        return [$state, $api_cols, $errors, $curl_stats];
    }

}

?>
