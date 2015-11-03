<?php

global $cookie_name, $ssoCharacterID, $ssoHash, $redis;

if ($ssoCharacterID != null && $ssoHash != null) {
        $value = $redis->del("login:$ssoCharacterID:$ssoHash");
}

session_unset();
$app->redirect('/', 302);
