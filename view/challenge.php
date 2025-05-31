<?php

global $redis, $ip;

$uri = $redis->get("ip::redirect::$ip");
if ($uri == "") $uri = "/";
$redis->del("ip::redirect::$ip");
$redis->setex("ip::challenge_safe::$ip", 3600, "true");
header("Location: $uri");
Util::zout("$ip successfully challenged and redirecting to $uri");
