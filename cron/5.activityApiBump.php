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
	$redis->setex("nextBumpPending", 310, "true");
}

function bump($key, $rtqName) {
	global $redis;

	$rtq = new RedisTimeQueue($rtqName, 3600);

	$values = $redis->keys("$key*");
	$count = 0;
	foreach ($values as $sID) {
		$sID = ((int) str_replace($key, "", $sID));
		if ($rtq->isMember($sID) === true && $redis->get("esi-fetched:$sID") != "true") {
			$rtq->setTime($sID, 1);
			$count++;
		}
	}
	Util::out("Bumped $count api verified active ids in $rtqName");

	return $rtqName;
}