<?php

global $cookie_name, $ssoCharacterID, $ssoHash, $redis;

if ($ssoCharacterID != null && $ssoHash != null) {
    $value = $redis->del("login:$ssoCharacterID:$ssoHash");
}

session_unset();
session_regenerate_id();
$app->redirect('/', 302);
