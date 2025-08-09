<?php

global $redis, $ip;

if ($redis->get("zkb:noapi") == "true") {
    return $app->render("error.html", ['message' => 'Downtime is not a good time to login, the CCP servers are not reliable, sorry.']);
}

if (@$_SESSION['characterID'] > 0) {
    return $app->render("error.html", ['message' => "Uh... you're already logged in..."]);
}

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

session_write_close();
$app->redirect($url, 302);
