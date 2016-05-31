<?php

global $redis;

$id = (int) $id;

$userID = User::getUserID();
$message = null;
if ($userID > 0 && $id > 0 && in_array($type, ['character', 'corporation', 'alliance'])) {
	$redisKey = "user:" . $userID;
	$mapKey = "tracker_" . $type;
	$tracked = UserConfig::get($mapKey, []);
	if ($action == 'add') {
		$tracked[] = $id;
	} else if ($action == 'remove') {
		unset($tracked[array_search($id, $tracked)]);
	}
	UserConfig::set($mapKey, $tracked);
}

$app->redirect("/$type/$id/");
