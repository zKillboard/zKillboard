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
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['redirect_url'] = $sso->getLoginURL($_SESSION);
	$GLOBALS['redirect_status'] = 302;
} else {
	$app->redirect($sso->getLoginURL($_SESSION), 302);
}
