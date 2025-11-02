<?php

global $cookie_name, $ssoCharacterID, $ssoHash, $redis;

if ($ssoCharacterID != null && $ssoHash != null) {
    $value = $redis->del("login:$ssoCharacterID:$ssoHash");
}

session_regenerate_id(true);
session_destroy();
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['redirect_url'] = '/';
	$GLOBALS['redirect_status'] = 302;
} else {
	$app->redirect('/', 302);
}
