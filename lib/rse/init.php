<?php
/**
 *  Copyright 2012 http://xuchaoqian.com/opensource/rse
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

namespace rse;

use \DateTime;

error_reporting(E_ALL);

require_once(__DIR__ . '/../../conf/rse.conf.php');
require_once(__DIR__ . '/verify.function.php');
require_once(__DIR__ . '/exception.class.php');
require_once(__DIR__ . '/logger.class.php');

verify_conf($GLOBALS['conf']);

if ( ! date_default_timezone_set($conf['timezone'])) {
    throw new exception('date_default_timezone_set() failed: timezone: ' . $GLOBALS['conf']['timezone']);
}

if ( ! defined('CURR_PID')) {
    if (($pid = getmypid()) === false ) {
        throw new exception('getmypid() failed!');
    }
    define('CURR_PID', $pid);
    unset($pid);
}

?>
