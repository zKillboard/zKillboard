<?php

global $cookie_name, $ssoCharacterID, $ssoHash, $redis;

if ($ssoCharacterID != null && $ssoHash != null) {
        $value = $redis->del("login:$ssoCharacterID:$ssoHash");
}

// remove the entry from the database
$sessionCookie = $app->getEncryptedCookie($cookie_name, false);
Db::execute('DELETE FROM zz_users_sessions WHERE sessionHash = :hash', array(':hash' => $sessionCookie));

session_unset();
$app->redirect('/', 302);
