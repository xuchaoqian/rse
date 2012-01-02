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

require_once(__DIR__ . '/init.php');
require_once(__DIR__ . '/verify.function.php');
require_once(__DIR__ . '/logger.class.php');

class executor {

    private $conf;
    private $logger;
    private $stderr_file_path;

    public function __construct($conf = array(), $logger = null) {
        verify_conf($conf);

        $this->conf = array_merge($GLOBALS['conf'], $conf);
        $this->logger = $logger ? $logger : (new logger($this->conf));
        $this->stderr_file_path = $this->build_stderr_file_path();
    }

    private function build_stderr_file_path() {
        return $this->conf['tmp_dir'] . '/use.stderr.' . CURR_PID;
    }

    public function run($host, $script_path, $args = array()) {
        try {
            verify_host($host);
            verify_script_path($script_path);
            verify_args($args);

            $local_script_path = $this->build_local_script_path($script_path);
            $remote_script_path = $this->build_remote_script_path($script_path);
            $cmd = $this->build_cmd($host, $local_script_path, $remote_script_path, $args);
            $result = $this->exec_cmd($cmd);

            if ($result['native_errno'] !== 0) {
                $this->handle_native_error(
                    $result['native_errno'], $host, $local_script_path, $remote_script_path);
                $result = $this->exec_cmd($cmd);
                if ($result['native_errno'] !== 0) {
                    throw new exception('Nested error!');
                }
            }
            if ( ! empty($result['user_stderr'])) {
                throw new exception($result['user_stderr']);
            }

            return $result['stdout'];
        } catch (exception $e) {
            $this->logger->log_error('exception: ' . $e->format_stack_trace());

            throw $e;
        }
    }

    private function build_local_script_path($script_path) {
        return "{$this->conf['scripts_dir']}/$script_path";
    }

    private function build_remote_script_path($script_path) {
        return "{$this->conf['remote_tmp_dir']}/$script_path";
    }

    private function build_cmd($host, $local_script_path, $remote_script_path, $args) {
        $remote_cmd = $this->build_remote_cmd(
            $host, $this->get_local_mod_time($local_script_path), $remote_script_path, $args);

        return "ssh {$this->conf['ssh_username']}@$host \"$remote_cmd\" 2>{$this->stderr_file_path} </dev/null";
    }

    private function get_local_mod_time($local_script_path) {
        clearstatcache();
        $fmt = filemtime($local_script_path);
        if (empty($fmt)) {
            throw new exception("filemtime() failed: file: $local_script_path");
        }
        return $fmt;
    }

    private function build_remote_cmd($host, $local_mod_time, $remote_script_path, $args) {
        $remote_cmd_header = $this->build_remote_cmd_header($host, $local_mod_time, $remote_script_path);
        $remote_cmd_body = $this->build_remote_cmd_body($remote_script_path, $args);

        return $remote_cmd_header . ' ' . $remote_cmd_body;
    }

    private function build_remote_cmd_header($host, $local_mod_time, $remote_script_path) {
        $remote_cmd_header = <<<EOD
if [[ ! -e {$this->conf['remote_tmp_dir']} ]]; then
    echo -n '-1|' >&2;
    exit -1;
fi
s='$remote_script_path';
if [[ ! -e \\\${s} ]]; then
    echo -n '-2|' >&2;
    exit -2;
fi
rmt=\`stat -c %Y \\\${s}\`;
if [[ $local_mod_time -gt \\\${rmt} ]]; then
    echo -n '-3|' >&2;
    exit -3;
fi
echo -n '0|' >&2;
cd {$this->conf['remote_tmp_dir']};
EOD;

        return $remote_cmd_header;
    }

    private function build_remote_cmd_body($remote_script_path, $args) {
        $arg_strs = array();
        foreach ($args as $arg) {
            if (is_numeric($arg)) {
                $arg_strs[] = $arg;
            } else {
                $arg = str_replace('\\', '\\\\', $arg);
                $arg = addslashes($arg);
                $arg = str_replace('$', '\\$', $arg);
                $arg_strs[] = "\$'$arg'";
            }
        }
        $args_str = implode(' ', $arg_strs);

        return "$remote_script_path $args_str;";
    }

    private function exec_cmd($cmd) {
        $raw_result = $this->exec_cmd_noparse($cmd);
        $stderr_array = $this->parse_stderr($raw_result['stderr']);

        return array('stdout'=>$raw_result['stdout'],
            'native_errno'=>$stderr_array['native_errno'],
            'user_stderr'=>$stderr_array['user_stderr']);
    }

    private function exec_cmd_noparse($cmd) {
        $this->logger->log_info("cmd: $cmd");

        $stdout = shell_exec($cmd);
        if ( ! empty($stdout)) {
            $this->logger->log_info("stdout: $stdout");
        }
        if (($stderr = file_get_contents($this->stderr_file_path)) === false) {
            throw new exception("file_get_contents() failed from stderr file: {$this->stderr_file_path}");
        }
        if (unlink($this->stderr_file_path) === false) {
            $this->logger->log_error("Can't delete file: {$this->stderr_file_path}");
        }
        $stderr = trim($stderr);
        if ($stderr !== '') {
            $this->logger->log_info("stderr: $stderr");
        }

        return array('stdout'=>$stdout, 'stderr'=>$stderr);
    }

    private function parse_stderr($stderr) {
        $raw_stderr_array = explode('|', $stderr, 2);
        if (count($raw_stderr_array) !== 2 || ! is_numeric($raw_stderr_array[0])) {
            throw new exception("Unexpected stderr: $stderr");
        }
        $native_errno = intval($raw_stderr_array[0]);
        if ( ! in_array($native_errno, array(0, -1, -2, -3))) {
            throw new exception("Unexpected native errno: $native_errno");
        }

        return array('native_errno'=>$native_errno, 'user_stderr'=>$raw_stderr_array[1]);
    }

    private function handle_native_error($native_errno, $host, $local_script_path, $remote_script_path) {
        if ($native_errno === -1 || $native_errno === -2) {
            $this->create_remote_base_dir($host, $remote_script_path);
            $this->sync_script_file($host, $local_script_path, $remote_script_path);
        } else if ($native_errno === -3) {
            $this->sync_script_file($host, $local_script_path, $remote_script_path);
        } else {
            throw new exception("Unexpected native errno: $native_errno");
        }
    }

    private function create_remote_base_dir($host, $remote_script_path) {
        $dirname = dirname($remote_script_path);
        $cmd = "ssh {$this->conf['ssh_username']}@$host 'mkdir -p $dirname' 2>{$this->stderr_file_path} </dev/null";

        $this->exec_cmd_autothrow($cmd);
    }

    private function exec_cmd_autothrow($cmd) {
        $raw_result = $this->exec_cmd_noparse($cmd);
        if ( ! empty($raw_result['stderr'])) {
            throw new exception("exec_cmd() failed: {$raw_result['stderr']}");
        }
        return $raw_result['stdout'];
    }

    private function sync_script_file($host, $local_script_path, $remote_script_path) {
        $cmd = "scp -p $local_script_path {$this->conf['ssh_username']}@$host:$remote_script_path 2>{$this->stderr_file_path} </dev/null";

        $this->exec_cmd_autothrow($cmd);
    }

    public function clean($host, $script_path = '') {
        verify_host($host);
        $script_path !== '' && verify_script_path($script_path);

        $path = $script_path ? $this->build_remote_script_path($script_path) : $this->conf['remote_tmp_dir'];
        $cmd = "ssh {$this->conf['ssh_username']}@$host 'rm -rf $path' 2>{$this->stderr_file_path} </dev/null";

        $this->exec_cmd_autothrow($cmd);
    }

}

?>
