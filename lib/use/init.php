<?php
/**
 *  Copyright 2012 http://xuchaoqian.com/opensource/use
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

namespace use_ns;

use \DateTime;

error_reporting(E_ALL);

require_once(__DIR__ . '/../../conf/use.php');
require_once(__DIR__ . '/exception.class.php');

if ( ! date_default_timezone_set($conf['timezone'])) {
    throw new exception('date_default_timezone_set() failed: timezone: ' . $conf['timezone']);
}

if ( ! defined('CURR_DATE_TIME')) {
    $dt = new DateTime();
    $dts = $dt->format('Y-m-d H:i:s');
    if ($dts === false) {
        throw new exception('format() failed!');
    }
    define('CURR_DATE_TIME', $dts);
    unset($dt, $dts);
}

if ( ! defined('CURR_HOST_NAME')) {
    $hostname = gethostname();
    if ($hostname === false) {
        throw new exception('gethostname() failed!');
    }
    define('CURR_HOST_NAME', $hostname);
    unset($hostname);
}

if ( ! defined('CURR_HOST_IP')) {
    $ip = gethostbyname(CURR_HOST_NAME);
    if ($ip === CURR_HOST_NAME) {
        throw new exception('gethostbyname() failed: name: ' . CURR_HOST_NAME);
    }
    define('CURR_HOST_IP', $ip);
    unset($ip);
}

if ( ! defined('CURR_PID')) {
    if (($pid = getmypid()) === false ) {
        throw new exception('getmypid() failed!');
    }
    define('CURR_PID', $pid);
    unset($pid);
}

?>
