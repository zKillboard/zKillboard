<?php

global $redis, $ip;

$uri = $redis->get("ip::redirect::$ip");
if ($uri == "") $uri = "/";
$redis->del("ip::redirect::$ip");
header("Location: $uri");
