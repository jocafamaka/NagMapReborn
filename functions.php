<?php
function filter_raw_data($raw_data, $filesName)
{
    include('config.php');
    include("langs/$nagMapR_Lang.php");

    $i = 0;
    $fileNum = 0;
    $lineNum = 1;
    foreach ($raw_data as $file) {
        foreach ($file as $line) {
            //remove blank spaces
            $line = trim($line);
            //remove comments from line
            $parts = explode(';', $line);
            $line = array_shift($parts);
            // if this is not an empty line or a comment...
            if ($line && !preg_match("/^;.?/", $line) && !preg_match("/^#.?/", $line)) {
                //replace many spaces with just one (or replace tab with one space)
                $line = preg_replace('/\s+/', ' ', $line);
                $line = preg_replace('/\t+/', ' ', $line);
                if (
                    (preg_match("/define host{/", $line)) or (preg_match("/define host {/", $line)) or (preg_match("/define hostextinfo {/", $line)) or (preg_match("/define hostextinfo{/", $line)) or (preg_match("/define hostgroup {/", $line)) or (preg_match("/define hostgroup{/", $line))
                ) {
                    //starting a new host definition
                    if (isset($in_definition) && $in_definition) {
                        die($in_definition_error);
                    }
                    $in_definition = 1;
                    $i++;
                } elseif (preg_match("/}/", $line)) {
                    $in_definition = 0;
                } elseif ($in_definition) {
                    //split line to options and values
                    $pieces = explode(" ", $line, 2);
                    //get rid of meaningless splits
                    if (count($pieces) < 2) {
                        continue;
                    };
                    $option = trim($pieces[0]);
                    $value = trim($pieces[1]);
                    $data[$i][$option] = $value;
                }
            }
            $lineNum++;
        }
        $lineNum = 1;
        $fileNum++;
    }
    return ($data);
}

function safe_name($in)
{
    $out = trim($in);
    $out = preg_replace('#[^a-z0-9A-Z]#', '_', $out);
    return $out;
}

function nagMapR_status()
{
    include('config.php');
    include("langs/$nagMapR_Lang.php");

    if (!file_exists($nagios_status_dat_file)) {
        die("</script>$nagios_status_dat_file $file_not_find_error");
    }
    $fp = fopen($nagios_status_dat_file, "r");
    $type = "";
    $data = array();
    while (!feof($fp)) {
        $line = trim(fgets($fp));
        //ignore all commented lines - hop to the next itteration
        if (empty($line) or preg_match("/^;/", $line) or preg_match("/^#/", $line)) {
            continue;
        }
        //if end of definition, skip to next itteration
        if (preg_match("/}/", $line)) {
            $type = "0";
            unset($host);
            unset($service);
            continue;
        }
        if (preg_match("/^hoststatus {/", $line)) {
            $type = "hoststatus";
        };
        if (preg_match("/^servicestatus {/", $line)) {
            $type = "servicestatus";
        };
        if (!preg_match("/}/", $line) && ($type == "hoststatus" | $type == "servicestatus")) {
            $line = trim($line);
            $pieces = explode("=", $line, 2);
            //do not bother with invalid data
            if (count($pieces) < 2) {
                continue;
            };
            $option = trim($pieces[0]);
            $value = trim($pieces[1]);
            if (($option == "host_name")) {
                $host = $value;
            }
            if (($option == "service_description")) {
                $service = $value;
            }

            if (($option == "current_state") && ($type == "servicestatus")) {

                $serviceBackup[$host] = ($value);   //Used if it is not included in the filters.

                if ($nagMapR_FilterService != '') {

                    $servicesFilter = explode(';', $nagMapR_FilterService);

                    foreach ($servicesFilter as $serviceFilter) {
                        if (strpos(strtoupper($service), strtoupper($serviceFilter)) !== false) {
                            $data[$host]['servStatus_CS'] = ($value);
                        }
                    }
                } else {
                    $data[$host]['servStatus_CS'] = ($value);
                }
            }
            if (($option == "current_state") && ($type == "hoststatus")) {

                $data[$host]['hostStatus_CS'] = ($value);
            }

            if ($nagMapR_ChangesBarMode == 2 || $nagMapR_ChangesBarMode == 3) {

                if (($option == "last_time_up") && ($type == "hoststatus")) {
                    $dataTime[$host]['time_LTU'] = $value;
                }
                if (($option == "last_state_change") && ($type == "servicestatus")) {
                    $dataTime[$host]['time_LSC'] = $value;
                }
            }
        }
    }

    foreach ($data as $key => $value) {
        if (!isset($data[$key]['servStatus_CS'])) {
            $data[$key]['servStatus_CS'] = $serviceBackup[$key];
        }

        if (($data[$key]['hostStatus_CS'] == 0) && ($data[$key]['servStatus_CS'] == 0)) {
            $data[$key]['status'] = 0;
        } elseif (($data[$key]['hostStatus_CS'] == 0) && ($data[$key]['servStatus_CS'] == 1)) {
            $data[$key]['status'] = 1;
        } elseif (($data[$key]['hostStatus_CS'] == 0) && ($data[$key]['servStatus_CS'] == 2)) {
            $data[$key]['status'] = 2;
        } elseif ($data[$key]['hostStatus_CS'] == 1) {
            $data[$key]['status'] = 3;
        } else {
            $data[$key]['status'] = 4;
        }
    }

    unset($serviceBackup);

    if ($nagMapR_ChangesBarMode == 2 || $nagMapR_ChangesBarMode == 3) {
        foreach ($data as $key => $value) {
            if ($data[$key]['status'] == 3)
                $data[$key]['time'] = $dataTime[$key]['time_LTU'];
            else
                $data[$key]['time'] = $dataTime[$key]['time_LSC'];
        }
    }

    return ($data);
}

// This is a function listing all files with Nagios configuration files into an array
// It reads nagios config file and parses out all directions for configuration directories or files
function get_config_files()
{
    include('config.php');
    include("langs/$nagMapR_Lang.php");

    if (!file_exists($nagios_cfg_file)) {
        die("<h1>Nagmap Reborn " . file_get_contents('VERSION') . "</h1><hr>$nagios_cfg_file $file_not_find_error");
    }

    $cfg_raw = file($nagios_cfg_file);

    foreach ($cfg_raw as $line) {
        $line = trim($line);
        if (preg_match("/^cfg_file/i", $line)) {
            $file = explode('=', $line, 2);
            $file[1] = trim($file[1]);
            $files[] = $file[1];
            unset($file);
        } elseif (preg_match("/^cfg_dir/i", $line)) {
            $dir = explode('=', $line, 2);
            $dir[1] = trim($dir[1]);
            read_recursive_dir($files, $dir[1]);
        }
    }
    $file_list = array_unique($files);
    return $file_list;
}

//Function to read recursively a config directory which contains symlinks
function read_recursive_dir(&$files, $dir)
{
    $dir_recursive = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($dir_recursive as $file => $object) {
        if (preg_match("/.cfg$/i", $file)) {
            $files[] = $file;
        } elseif (is_link($file) || (is_dir($file) && !preg_match("/\.$/i", $file))) {
            read_recursive_dir($files, $file);
        }
    }
}

function require_auth()
{
    include('config.php');
    if ($nagMapR_useAuth == 1) {
        include("langs/$nagMapR_Lang.php");
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        $is_not_authenticated = (empty($_SERVER['PHP_AUTH_USER']) ||
            empty($_SERVER['PHP_AUTH_PW']) ||
            $_SERVER['PHP_AUTH_USER'] != $nagMapR_User ||
            $_SERVER['PHP_AUTH_PW']   != $nagMapR_UserKey);
        if ($is_not_authenticated) {
            header('HTTP/1.1 401 Authorization Required');
            header('WWW-Authenticate: Basic realm="Access denied"');
            die("<h1>Nagmap Reborn " . file_get_contents('VERSION') . "</h1><hr><strong>ERROR 401:</strong> " . $authFail);
        }
    }
}
