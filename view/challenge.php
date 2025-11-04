<?php

function handler($request, $response, $args, $container) {
    global $redis, $ip;

    $uri = $redis->get("ip::redirect::$ip");
    if ($uri == "") $uri = "/";
    $redis->del("ip::redirect::$ip");
    $redis->setex("ip::challenge_safe::$ip", 3600, "true");
    
    Util::zout("$ip successfully challenged and redirecting to $uri");
    return $response->withStatus(302)->withHeader('Location', $uri);
}
