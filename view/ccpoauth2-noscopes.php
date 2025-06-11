<?php

global $redis;

session_destroy();
session_start();

$sessID = session_id();
$uri = @$_SERVER['HTTP_REFERER'];
if ($uri != '' && $redis->get("forward:$sessID") == null) {
    $redis->setex("forward:$sessID", 300, $uri);
}

$sso = ZKillSSO::getSSO(['publicData']);
$app->redirect($sso->getLoginURL($_SESSION), 302);
