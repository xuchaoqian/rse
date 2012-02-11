<?php
/**
* Copyright 2012 http://xuchaoqian.com/opensource/rse
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

namespace rse;

// for date_default_timezone_set
$conf['timezone'] = 'Asia/Chongqing';

$conf['scripts_dir'] = __DIR__ . '/../scripts';

$conf['log_file'] = __DIR__ . '/../log/rse.log';

// for placing local tmp file
$conf['tmp_dir'] = __DIR__ . '/../tmp';

$conf['ssh_user'] = 'xcq';

$conf['remote_tmp_dir'] = '/tmp/rse';

?>
