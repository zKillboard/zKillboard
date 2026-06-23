<?php

function handler($request, $response, $args, $container) {
    global $redis, $ip, $version, $templates;

    $redis->setex("validUser:$ip", 300, "true");

    $ug = new UserGlobals();
    $arr = $ug->getGlobals();
    $etag = md5(serialize($arr) . date('YmdHmis'));
    $etag = 'W/"' . $etag . '"';
    
    $data = ['killsLastHour' => $redis->get("tqKillCount")];
    $result = $container->get('view')->render($response, 'components/nav-tracker.pug', $data);
    
    return $result->withHeader('ETag', $etag)->withHeader('Cache-Control', 'private')->withHeader('Cache-Tag', 'www,navbar');
}
