<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$waitfor = [];
$ttl = max(1, $redis->ttl("nextBumpPending"));
if ($ttl < 60) {
	sleep($ttl + 1);
	$waitfor[] = bump("recentKillmailActivity:corp:", "tqCorpApiESI");
	$waitfor[] = bump("recentKillmailActivity:char:", "tqApiESI");
	foreach ($waitfor as $rtqName) {
		$rtq = new RedisTimeQueue($rtqName, 3600);
		while ($rtq->pending() > 10) sleep(1);
	}
	$redis->setex("nextBumpPending", 300, "true");
}

function bump($key, $rtqName) {
	global $redis;

	$rtq = new RedisTimeQueue($rtqName, 3600);

	Util::out("Bumping active ids in $rtqName");

	$values = $redis->keys("$key*");
	foreach ($values as $sID) {
		$sID = ((int) str_replace($key, "", $sID));
		if ($rtq->isMember($sID) === true) $rtq->setTime($sID, 1);
	}
	

	return $rtqName;
}