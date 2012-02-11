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

require_once(__DIR__ . '/init.php');

use common\exception;
use common\logger;

class request {
    private $ssh_user;
    private $ssh_host;
    private $remote_cmd_header;
    private $remote_cmd_body;
    private $stderr_file_path;

    public function __construct(
        $ssh_user, $ssh_host, $remote_cmd_header, $remote_cmd_body, $stderr_file_path
    ) {
        $this->ssh_user = $ssh_user;
        $this->ssh_host = $ssh_host;
        $this->remote_cmd_header = $remote_cmd_header;
        $this->remote_cmd_body = $remote_cmd_body;
        $this->stderr_file_path = $stderr_file_path;
    }

    public function __get($name) {
        if ( ! property_exists($this, $name)) {
            throw new exception("Undefined property name: $name");
        }
        return $this->$name;
    }

    public function __isset($name) {
        if (isset($this->$name)) {
            return empty($this->$name) === false;
        } else {
            return null;
        }
        return $this->$name;
    }

    public function __tostring() {
        return "ssh {$this->ssh_user}@{$this->ssh_host} \"{$this->remote_cmd_body}\" "
            . "2>{$this->stderr_file_path} </dev/null";
    }

    public function to_cmd() {
        return "ssh {$this->ssh_user}@{$this->ssh_host} "
            . "\"{$this->remote_cmd_header} {$this->remote_cmd_body}\" "
            . "2>{$this->stderr_file_path} </dev/null";
    }
}

class response {
    private $native_errno;
    private $native_errmsg;
    private $user_stdout;
    private $user_stderr;

    public function __construct($native_errno, $native_errmsg, $user_stdout, $user_stderr) {
        $this->native_errno = $native_errno;
        $this->native_errmsg = $native_errmsg;
        $this->user_stdout = $user_stdout;
        $this->user_stderr = $user_stderr;
    }

    public function __get($name) {
        if ( ! property_exists($this, $name)) {
            throw new exception("Undefined property name: $name");
        }
        return $this->$name;
    }

    public function __isset($name) {
        if (isset($this->$name)) {
            return empty($this->$name) === false;
        } else {
            return null;
        }
        return $this->$name;
    }
}

class executor {

    private $conf;
    private $logger;
    private $stderr_file_path;

    public function __construct($conf, $logger) {
        $this->conf = $conf;
        $this->logger = $logger;
        $this->stderr_file_path = $this->build_stderr_file_path();
    }

    private function build_stderr_file_path() {
        return $this->conf['tmp_dir'] . '/rse.stderr.' . CURR_PID;
    }

    public function run($host, $script_path, $args = array()) {
        try {
            verify_host($host);
            verify_script_path($script_path);
            verify_args($args);

            $local_script_path = $this->build_local_script_path($script_path);
            $remote_script_path = $this->build_remote_script_path($script_path);
            $dep_script_paths = $this->parse_dep_script_paths($script_path);
            $request = $this->build_request(
                    $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
                );
            $response = $this->send_request($request);

            if ($response->native_errno !== 0) {
                $this->handle_native_error($response->native_errno, $response->native_errmsg,
                        $host, $local_script_path, $remote_script_path, $dep_script_paths
                    );
                $response = $this->send_request($request);
                if ($response->native_errno !== 0) {
                    throw new exception('Nested error!');
                }
            }
            if ($response->user_stderr !== '') {
                throw new exception($response->user_stderr);
            }

            return $response->user_stdout;
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

    private function parse_dep_script_paths($script_path) {
        $dep_script_set = array();
        $this->parse_dep_script_paths_recursively($script_path, $dep_script_set);
        return array_keys($dep_script_set);
    }

    private function parse_dep_script_paths_recursively(
        $script_path, &$dep_script_set, &$parsing_script_set = array()
    ) {
        if ( ! empty($parsing_script_set[$script_path])) {
            return;
        }
        $local_script_path = $this->build_local_script_path($script_path);
        $handle = fopen($local_script_path, 'r');
        if ( ! $handle) {
            throw new exception("Cannot open file: $local_script_path");
        }
        $parsing_script_set[$script_path] = 1;

        $count = 0;
        while (($buffer = fgets($handle)) !== false) {
            if (trim($buffer) === '') {
                continue;
            }
            if (++$count === 2) {
                $prefix = substr($buffer, 0, 8);
                if ($prefix === '#include') {
                    $script_paths_str = substr($buffer, 8);
                    $script_paths = explode(' ', $script_paths_str);
                    foreach ($script_paths as $script_path) {
                        $script_path = trim($script_path);
                        if ($script_path === '') {
                            continue;
                        }
                        $dep_script_set[$script_path] = 1;
                        $this->parse_dep_script_paths_recursively(
                            $script_path, $dep_script_set, $parsing_script_set
                        );
                    }
                }
                break;
            }
        }

        fclose($handle);
    }

    private function build_request(
        $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
    ) {
        $remote_cmd_header = $this->build_remote_cmd_header(
                $local_script_path, $remote_script_path, $dep_script_paths
            );
        $remote_cmd_body = $this->build_remote_cmd_body($remote_script_path, $args);

        return new request($this->conf['ssh_user'], $host, $remote_cmd_header,
                $remote_cmd_body, $this->stderr_file_path);
    }

    private function build_remote_cmd_header(
        $local_script_path, $remote_script_path, $dep_script_paths
    ) {
        $file_list = array();
        $file_list[] = $remote_script_path;
        $time_list = array();
        $time_list[] = $this->get_local_mod_time($local_script_path);
        foreach ($dep_script_paths as $dep_script_path) {
            $file_list[] = $this->build_remote_script_path($dep_script_path);
            $time_list[] = $this->get_local_mod_time(
                    $this->build_local_script_path($dep_script_path)
                );
        }
        $file_list_str = implode(' ', $file_list);
        $time_list_str = implode(' ', $time_list);

        $remote_cmd_header = <<<EOD
if [[ ! -e {$this->conf['remote_tmp_dir']} ]]; then
    echo -n '-1|' >&2;
    exit -1;
fi
fl=($file_list_str);
tl=($time_list_str);
mfl='';
for ((i=0; i<\\\${#fl[@]}; i++)); do
    f=\\\${fl[\\\$i]};
    if [[ -e \\\${f} ]]; then
        rmt=\`stat -c %Y \\\${f}\`;
        if [[ \\\${tl[\\\$i]} -gt \\\${rmt} ]]; then
            mfl=\\"\\\${mfl} \\\${i}\\";
        fi
    else
        mfl=\\"\\\${mfl} \\\${i}\\";
    fi
done
mfl=\\"\\\${mfl## }\\";
if [[ ! -z \\\${mfl} ]]; then
    echo -n \\"-2,\\\${mfl}|\\" >&2;
    exit -2;
fi
echo -n '0|' >&2;
cd {$this->conf['remote_tmp_dir']};
EOD;

        return $remote_cmd_header;
    }

    private function get_local_mod_time($local_script_path) {
        clearstatcache();
        $fmt = filemtime($local_script_path);
        if (empty($fmt)) {
            throw new exception("filemtime() failed: file: $local_script_path");
        }
        return $fmt;
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

        if ($args_str) {
            return "$remote_script_path $args_str";
        } else {
            return "$remote_script_path";
        }
    }

    private function send_request($request) {
        $this->logger->log_info("request: $request");

        $raw_result = $this->exec_cmd_raw($request->to_cmd());
        if ($raw_result['stdout'] !== null) {
            $this->logger->log_info("user_stdout: {$raw_result['stdout']}");
        }
        $stderr_array = $this->parse_stderr($raw_result['stderr']);
        if ($stderr_array['user_stderr'] !== '') {
            $this->logger->log_info("user_stderr: {$stderr_array['user_stderr']}");
        }
        if ($stderr_array['native_errno'] !== 0) {
            if ($stderr_array['native_errmsg'] !== '') {
                $this->logger->log_info('native_stderr: '
                    . "{$stderr_array['native_errno']},{$stderr_array['native_errmsg']}");
            } else {
                $this->logger->log_info("native_stderr: {$stderr_array['native_errno']}");
            }
        }

        return new response($stderr_array['native_errno'], $stderr_array['native_errmsg'],
            $raw_result['stdout'], $stderr_array['user_stderr']);
    }

    private function exec_cmd_raw($cmd) {
        $stdout = shell_exec($cmd);

        if (($stderr = file_get_contents($this->stderr_file_path)) === false) {
            throw new exception(
                    "file_get_contents() failed from stderr file: {$this->stderr_file_path}"
                );
        }
        if (unlink($this->stderr_file_path) === false) {
            $this->logger->log_error("Can't delete file: {$this->stderr_file_path}");
        }
        $stderr = trim($stderr);

        return array('stdout'=>$stdout, 'stderr'=>$stderr);
    }

    private function parse_stderr($stderr) {
        $raw_stderr_array = explode('|', $stderr, 2);
        if (count($raw_stderr_array) !== 2) {
            throw new exception("Unexpected stderr: $stderr");
        }

        $native_stderr_array = explode(',', $raw_stderr_array[0]);
        if (count($native_stderr_array) === 1) {
            $native_errno = intval($native_stderr_array[0]);
            $native_errmsg = '';
        } else if (count($native_stderr_array) === 2) {
            $native_errno = intval($native_stderr_array[0]);
            $native_errmsg = $native_stderr_array[1];
        } else {
            throw new exception("Unexpected native stderr: {$raw_stderr_array[0]}");
        }
        if ( ! in_array($native_errno, array(0, -1, -2))) {
            throw new exception("Unexpected native errno: $native_errno");
        }

        return array('native_errno'=>$native_errno, 'native_errmsg'=>$native_errmsg,
                'user_stderr'=>$raw_stderr_array[1]);
    }

    private function handle_native_error($native_errno, $native_errmsg, $host, $local_script_path,
            $remote_script_path, $dep_script_paths) {
        $local_script_paths = array($local_script_path);
        $remote_script_paths = array($remote_script_path);
        foreach ($dep_script_paths as $dep_script_path) {
            $local_script_paths[] = $this->build_local_script_path($dep_script_path);
            $remote_script_paths[] = $this->build_remote_script_path($dep_script_path);
        }

        if ($native_errno === -1) {
            $remote_base_dirs = array();
            foreach ($remote_script_paths as $remote_script_path) {
                $remote_base_dirs[] = dirname($remote_script_path);
            }
            $this->create_remote_base_dirs($host, array_unique($remote_base_dirs));
            $this->sync_script_files($host, $local_script_paths, $remote_base_dirs);
        } else if ($native_errno === -2) {
            $str_indexes = explode(' ', $native_errmsg);
            $miss_remote_base_dirs = array();
            $miss_local_script_paths = array();
            foreach ($str_indexes as $str_index) {
                if (trim($str_index) === '') {
                    continue;
                }
                $index = (integer)$str_index;
                $miss_remote_base_dirs[] = dirname($remote_script_paths[$index]);
                $miss_local_script_paths[] = $local_script_paths[$index];
            }
            $this->create_remote_base_dirs($host, array_unique($miss_remote_base_dirs));
            $this->sync_script_files($host, $miss_local_script_paths, $miss_remote_base_dirs);
        } else {
            throw new exception("Unexpected native errno: $native_errno");
        }
    }

    private function create_remote_base_dirs($host, $remote_base_dirs) {
        $dirs_str = implode(' ', $remote_base_dirs);
        $cmd = "ssh {$this->conf['ssh_user']}@$host 'mkdir -p $dirs_str'"
                . " 2>{$this->stderr_file_path} </dev/null";

        $this->exec_cmd_autothrow($cmd);
    }

    private function exec_cmd_autothrow($cmd) {
        $this->logger->log_info("native_cmd: $cmd");

        $raw_result = $this->exec_cmd_raw($cmd);
        if ($raw_result['stdout'] !== null) {
            $this->logger->log_info("stdout: {$raw_result['stdout']}");
        }
        if ($raw_result['stderr'] !== '') {
            $this->logger->log_info("stderr: {$raw_result['stderr']}");
            throw new exception("exec_cmd() failed: {$raw_result['stderr']}");
        }

        return $raw_result['stdout'];
    }

    private function sync_script_files($host, $local_script_paths, $remote_base_dirs) {
        $grped_local_script_paths = array();
        for ($i = 0, $len = count($local_script_paths); $i < $len; ++$i) {
            $remote_base_dir = $remote_base_dirs[$i];
            if ( ! isset($grped_local_script_paths[$remote_base_dir])) {
                $grped_local_script_paths[$remote_base_dir] = array($local_script_paths[$i]);
            } else {
                $grped_local_script_paths[$remote_base_dir][] = $local_script_paths[$i];
            }
        }
        foreach ($grped_local_script_paths as $remote_base_dir => $local_script_paths_per_grp) {
            $paths_str = implode(' ', $local_script_paths_per_grp);
            $cmd = "scp -p {$paths_str} {$this->conf['ssh_user']}@$host:{$remote_base_dir}"
                    . " 2>{$this->stderr_file_path} </dev/null";

            $this->exec_cmd_autothrow($cmd);
        }
    }

    public function clean($host, $script_path = '') {
        verify_host($host);
        $script_path !== '' && verify_script_path($script_path);

        $path = $script_path ? $this->build_remote_script_path($script_path)
                : $this->conf['remote_tmp_dir'];
        $cmd = "ssh {$this->conf['ssh_user']}@$host 'rm -rf $path' 2>{$this->stderr_file_path}"
                . ' </dev/null';

        $this->exec_cmd_autothrow($cmd);
    }

}

?>
