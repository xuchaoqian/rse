<?php
/**
 *  Copyright 2012 http://xuchaoqian.com/opensource/common
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

namespace common;

require_once(__DIR__ . '/exception.class.php');

use \DateTime;

class logger{

    private $log_file_path;

    public function __construct($log_file) {
        $date_time = new DateTime();
        $fdt = $date_time->format('Ymd');
        $this->log_file_path = "$log_file.$fdt";
    }

    public function log_debug($msg) {
        $previos_frame = $this->parse_previous_frame(debug_backtrace(0|DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->log('DEBUG', $previos_frame, $msg);
    }

    private function parse_previous_frame($debug_backtrace) {
        if (empty($debug_backtrace[1])) {
            return '';
        }

        $frame = $debug_backtrace[1];

        if (empty($frame['class']) || empty($frame['type']) || empty($frame['function'])) {
            return "{$frame['file']}:{$frame['line']}";
        }

        return "{$frame['class']}{$frame['type']}{$frame['function']}()";
    }

    public function log_info($msg) {
        $previos_frame = $this->parse_previous_frame(debug_backtrace(0|DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->log('INFO', $previos_frame, $msg);
    }

    public function log_error($msg) {
        $previos_frame = $this->parse_previous_frame(debug_backtrace(0|DEBUG_BACKTRACE_IGNORE_ARGS));

        $this->log('ERROR', $previos_frame, $msg);
    }

    private function log($type, $previos_frame, $msg) {
        $date_time = new DateTime();
        $fdt = $date_time->format('Y-m-d H:i:s');
        if ($msg[strlen($msg)-1] == "\n") {
            $nl = '';
        } else {
            $nl = "\r\n";
        }
        $msg = "$fdt $type " . CURR_PID . ($previos_frame ? " $previos_frame " : ' ') . "- $msg$nl";
        file_put_contents($this->log_file_path, $msg, FILE_APPEND);
    }

}

?>
