<?php

global $redis, $ip;

$sessID = session_id();

$delayInt = isset($delay) ? (int) $delay : 0;
if ($delayInt > 0 && $delayInt <= 5) {
    $redis->setex("delay:$sessID", 900, $delay);
} else $redis->del("delay:$sessID");

$uri = @$_SERVER['HTTP_REFERER'];
if ($uri != '' && $redis->get("forward:$sessID") == null) {
    $redis->setex("forward:$sessID", 900, $uri);
}

$sso = ZKillSSO::getSSO();
$url = $sso->getLoginURL($_SESSION);

//Util::zout("$ip $sessID starting a login session " . print_r($_SESSION, true));
$app->redirect($url, 302);
