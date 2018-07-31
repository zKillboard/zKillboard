<?php

global $redis, $ip;

$uri = $redis->get("ip::redirect::$ip");
if ($uri == "") $uri = "/";
$redis->del("ip::redirect::$ip");
$redis->setex("ip::challenge_safe::$ip", 300, "true");
header("Location: $uri");
