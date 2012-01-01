<?php

namespace use_ns;

require_once(__DIR__ . '/init.php');
require_once(__DIR__ . '/verify.function.php');

use \DateTime;

class logger{

    private $date_time;
    private $log_file_path;

    public function __construct($conf = array()) {
        verify_conf($conf);

        $this->date_time = new DateTime();
        $fdt = $this->date_time->format('Ymd');
        $this->log_file_path = "{$GLOBALS['conf']['log_dir']}/use.log.$fdt";
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
        $fdt = $this->date_time->format('Y-m-d H:i:s');
        $msg = "$fdt $type " . CURR_PID . ($previos_frame ? " $previos_frame " : ' ') . "- $msg\r\n";
        file_put_contents($this->log_file_path, $msg, FILE_APPEND);
    }

}

?>
