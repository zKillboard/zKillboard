<?php

require_once "../init.php";

$key = "zkb:userSettingsLoaded";
if ($redis->get($key) == true) exit();

Util::out("Loading user settings");

$users = $mdb->find("users");
foreach ($users as $user) {
	$key = $user['userID'];
	unset($user['userID']);
	unset($user['_id']);
	$redis->hMSet($key, $user);
}

// Make this permanent to the life of Redis
$redis->set($key, true);
