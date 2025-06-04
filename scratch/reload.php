<?php

require_once "../init.php";

global $redis;

$redisMessage = ['action' => 'reload'];
$redis->publish('public', json_encode($redisMessage, JSON_UNESCAPED_SLASHES));
