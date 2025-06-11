<?php

global $redis, $ip;

session_destroy();
session_start();

$sessID = session_id();
$uri = @$_SERVER['HTTP_REFERER'];
if ($uri != '' && $redis->get("forward:$sessID") == null) {
    $redis->setex("forward:$sessID", 300, $uri);
}

$sso = ZKillSSO::getSSO();
$url = $sso->getLoginURL($_SESSION);

//Util::zout("$ip $sessID starting a login session " . print_r($_SESSION, true));
$app->redirect($url, 302);
