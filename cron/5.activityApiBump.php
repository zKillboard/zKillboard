<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$waitfor = [];
$ttl = max(1, $redis->ttl("nextBumpPending"));
if (true || $ttl < 60) {
	//sleep($ttl + 1);
	$waitfor[] = bump("recentKillmailActivity:corp:", "tqCorpApiESI", "corporationID");
	$waitfor[] = bump("recentKillmailActivity:char:", "tqApiESI", "characterID");
	foreach ($waitfor as $rtqName) {
		$rtq = new RedisTimeQueue($rtqName, 3600);
		while ($rtq->pending() > 10) sleep(1);
	}
	$redis->setex("nextBumpPending", 310, "true");
}

function bump($key, $rtqName, $type) {
	global $redis;

	$rtq = new RedisTimeQueue($rtqName, 3600);

	$values = $redis->keys("$key*");
	$count = 0;
	foreach ($values as $sID) {
		$sID = ((int) str_replace($key, "", $sID));
		if ($rtq->isMember($sID) === true && $redis->get("esi-fetched:$sID") != "true" && $sID > "199999") {
			$rtq->setTime($sID, 1);
			$count++;
            //Util::out("$type Bumping: " . Info::getInfoField($type, (int) $sID, "name"));
		}
	}
	Util::out("Bumped $count api verified active ids in $rtqName");

	return $rtqName;
}
