<?php

global $redis;

header('Location: /ccpoauth2/', 302);
die();

Log::log('another login attempt');
die('OAUTH2 implementation in progress. Logging in and auth ESI endpoints will be disabled or not working.');

$sessID = session_id();
$uri = @$_SERVER['HTTP_REFERER'];
if ($uri != '') {
    $redis->setex("forward:$sessID", 300, $uri);
}

CrestSSO::login();
