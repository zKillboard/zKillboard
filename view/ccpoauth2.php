<?php

global $redis;

$sessID = session_id();
$uri = @$_SERVER['HTTP_REFERER'];
if ($uri != '') {
    $redis->setex("forward:$sessID", 300, $uri);
}


$sso = EveOnlineSSO::getSSO();
$app->redirect($sso->getLoginURL($_SESSION), 302);
