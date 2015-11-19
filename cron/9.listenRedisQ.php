<?php

require_once "../init.php";

// If you want your zkillboard to listen to RedisQ, add the following line to config.php
// $listenRedisQ = true;

global $listenRedisQ;

if ($listenRedisQ == null || $listenRedisQ == false) exit();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "http://redisq.zkillboard.com/listen.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

$timer = new Timer();
while ($timer->stop() < 58000) {
	$raw = curl_exec($ch);
	$json = json_decode($raw, true);
	$killmail = $json['package'];
	if ($killmail == null) continue;

	$killID = $killmail['killID'];
	$hash = $killmail['zkb']['hash'];
	if (!$mdb->exists("crestmails", ['killID' => $killID, 'hash' => $hash])) $mdb->save("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
}

curl_close($ch);
