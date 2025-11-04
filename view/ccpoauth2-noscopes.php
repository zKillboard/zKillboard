<?php

function handler($request, $response, $args, $container) {
    global $redis;

    session_destroy();
    session_start();

    $sessID = session_id();
    $uri = @$_SERVER['HTTP_REFERER'];
    if ($uri != '' && $redis->get("forward:$sessID") == null) {
        $redis->setex("forward:$sessID", 300, $uri);
    }

    $sso = ZKillSSO::getSSO(['publicData']);
    return $response->withStatus(302)->withHeader('Location', $sso->getLoginURL($_SESSION));
}
