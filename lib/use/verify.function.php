<?php

namespace use_ns;

require_once(__DIR__ . '/init.php');

function verify_conf($conf) {
    foreach($GLOBALS['conf'] as $key => $value) {
        if ($key === 'timezone') {
            ;
        } else if ($key === 'scripts_dir') {
            ;
        } else if ($key === 'log_dir') {
            ;
        } else if ($key === 'tmp_dir') {
            ;
        } else if ($key === 'ssh_username') {
            ;
        } else if ($key === 'remote_tmp_dir') {
            ;
        } else {
            throw new exception("Undefined conf key: $key");
        }
    }
}

function verify_host($host) {
    if (empty($host)) {
        throw new exception('Host can\'t be empty!');
    }
}

function verify_script_path($script_path) {
    if (empty($script_path)) {
        throw new exception('Script can\'t be empty!');
    }
    if ($script_path[0] === '/') {
        throw new exception("Expect relative path, but: $script_path");
    }
    if ( ! file_exists("{$GLOBALS['conf']['scripts_dir']}/$script_path")) {
        throw new exception("Can't find script: $script_path");
    }
}

function verify_args($args) {
    foreach ($args as $arg) {
        if (is_string($arg) || is_integer($arg) || is_float($arg)) {
            ;
        } else {
            throw new exception('Undefined arg type: ' . gettype($arg) . ' occured'
                . ' when verifying args: ' . var_export($args, true));
        }
    }
}

?>
