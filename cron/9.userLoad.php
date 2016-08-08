<?php

require_once '../init.php';

$loadKey = 'zkb:userSettingsLoaded';
if ($redis->get($loadKey) == "loaded") {
    exit();
}

$users = $mdb->find('users');
foreach ($users as $user) {
    $key = $user['userID'];
    if (!$redis->exists($key)) {
        Util::out("Loading $key");
        unset($user['userID']);
        unset($user['_id']);
        $redis->hMSet($key, $user);
    }
}

$redis->setex($loadKey, 3600, "loaded");
