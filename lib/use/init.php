<?php

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
