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

use \Exception as native_exception;

class exception extends native_exception {

    public function get_msg() {
        return $this->getMessage();
    }

    public function get_code() {
        return $this->getCode();
    }

    public function get_file() {
        return $this->getFile();
    }

    public function get_line() {
        return $this->getLine();
    }

    public function get_previous() {
        return $this->getPrevious();
    }

    public function get_stack_trace() {
        $trace_elems = array();

        $i = 0;
        $e = $this;
        do {
            $trace_elems[$i]['msg'] = $e->getMessage();
            $trace_elems[$i]['code'] = $e->getCode();
            $trace_elems[$i]['file'] = $e->getFile();
            $trace_elems[$i]['line'] = $e->getLine();
            $trace_elems[$i]['trace'] = $e->getTrace();
            ++$i;
            $e = $e->getPrevious();
        } while ($e);

        return $trace_elems;
    }

    public function format_stack_trace($lf = "\r\n") {
        $trace = '';

        $i = 0;
        foreach ($this->get_stack_trace() as $trace_elem) {
            if ($i > 0) {
                $trace .= 'Caused by: ';
            }

            $trace .= get_class($this) . ": {$trace_elem['msg']}$lf";

            $j = 0;
            $thrown_at = "Thrown at [{$trace_elem['file']}:{$trace_elem['line']}]";
            $trace .= "\t#$j {$thrown_at}$lf";
            ++$j;

            foreach ($trace_elem['trace'] as $trace_item) {
                $class = empty($trace_item['class']) ? '' : $trace_item['class'];
                $type = empty($trace_item['type']) ? '' : $trace_item['type'];

                $args = '';
                $k = 0;
                foreach ($trace_item['args'] as $arg) {
                    if ($k > 0) {
                        $args .= ',';
                    }

                    $args .= gettype($arg);

                    ++$k;
                }

                $called_at = "Called at {$class}{$type}{$trace_item['function']}($args) "
                    . "[{$trace_item['file']}:{$trace_item['line']}]";
                $trace .= "\t#$j {$called_at}$lf";

                ++$j;
            }

            ++$i;
        }

        return $trace;
    }
}

?>
