<?php

global $cookie_name, $ssoCharacterID, $ssoHash, $redis;

if ($ssoCharacterID != null && $ssoHash != null) {
    $value = $redis->del("login:$ssoCharacterID:$ssoHash");
}

session_regenerate_id(true);
session_destroy();
$app->redirect('/', 302);
