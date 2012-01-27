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
        return $this->conf['tmp_dir'] . '/use.stderr.' . CURR_PID;
    }

    public function run($host, $script_path, $args = array()) {
        try {
            verify_host($host);
            verify_script_path($script_path);
            verify_args($args);

            $local_script_path = $this->build_local_script_path($script_path);
            $remote_script_path = $this->build_remote_script_path($script_path);
            $dep_script_paths = $this->parse_dep_script_paths($script_path);
            $cmd = $this->build_cmd(
                    $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
                );
            $result = $this->exec_cmd($cmd);

            if ($result['native_errno'] !== 0) {
                $this->handle_native_error($result['native_errno'], $result['native_errmsg'],
                        $host, $local_script_path, $remote_script_path, $dep_script_paths
                    );
                $result = $this->exec_cmd($cmd);
                if ($result['native_errno'] !== 0) {
                    throw new exception('Nested error!');
                }
            }
            if ( ! empty($result['user_error'])) {
                throw new exception($result['user_error']);
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

    private function build_cmd(
        $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
    ) {
        $remote_cmd = $this->build_remote_cmd(
                $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
            );

        return "ssh {$this->conf['ssh_username']}@$host \"$remote_cmd\" 2>{$this->stderr_file_path}"
                . ' </dev/null';
    }

    private function build_remote_cmd(
        $host, $local_script_path, $remote_script_path, $dep_script_paths, $args
    ) {
        $remote_cmd_header = $this->build_remote_cmd_header(
                $host, $local_script_path, $remote_script_path, $dep_script_paths
            );
        $remote_cmd_body = $this->build_remote_cmd_body($remote_script_path, $args);

        return $remote_cmd_header . ' ' . $remote_cmd_body;
    }

    private function build_remote_cmd_header(
        $host, $local_script_path, $remote_script_path, $dep_script_paths
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

        return "$remote_script_path $args_str;";
    }

    private function exec_cmd($cmd) {
        $raw_result = $this->exec_cmd_noparse($cmd);
        $stderr_array = $this->parse_stderr($raw_result['stderr']);

        return array('stdout'=>$raw_result['stdout'],
                'native_errno'=>$stderr_array['native_errno'],
                'native_errmsg'=>$stderr_array['native_errmsg'],
                'user_error'=>$stderr_array['user_error']);
    }

    private function exec_cmd_noparse($cmd) {
        $this->logger->log_info("cmd: $cmd");

        $stdout = shell_exec($cmd);
        if ( ! empty($stdout)) {
            $this->logger->log_info("stdout: $stdout");
        }
        if (($stderr = file_get_contents($this->stderr_file_path)) === false) {
            throw new exception(
                    "file_get_contents() failed from stderr file: {$this->stderr_file_path}"
                );
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
                'user_error'=>$raw_stderr_array[1]);
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
        $cmd = "ssh {$this->conf['ssh_username']}@$host 'mkdir -p $dirs_str'"
                . " 2>{$this->stderr_file_path} </dev/null";

        $this->exec_cmd_autothrow($cmd);
    }

    private function exec_cmd_autothrow($cmd) {
        $raw_result = $this->exec_cmd_noparse($cmd);
        if ( ! empty($raw_result['stderr'])) {
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
            $cmd = "scp -p {$paths_str} {$this->conf['ssh_username']}@$host:{$remote_base_dir}"
                    . " 2>{$this->stderr_file_path} </dev/null";

            $this->exec_cmd_autothrow($cmd);
        }
    }

    public function clean($host, $script_path = '') {
        verify_host($host);
        $script_path !== '' && verify_script_path($script_path);

        $path = $script_path ? $this->build_remote_script_path($script_path)
                : $this->conf['remote_tmp_dir'];
        $cmd = "ssh {$this->conf['ssh_username']}@$host 'rm -rf $path' 2>{$this->stderr_file_path}"
                . ' </dev/null';

        $this->exec_cmd_autothrow($cmd);
    }

}

?>
