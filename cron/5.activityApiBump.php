<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

$waitfor = [];
$waitfor[] = bump("recentKillmailActivity:corp:", "tqCorpApiESI", "corporationID");
$waitfor[] = bump("recentKillmailActivity:char:", "tqApiESI", "characterID");

function bump($key, $rtqName, $type) {
	global $redis;

	$rtq = new RedisTimeQueue($rtqName, 3600);

	$values = $redis->keys("$key*");
	$count = 0;
	foreach ($values as $sID) {
		$sID = ((int) str_replace($key, "", $sID));
		if ($rtq->isMember($sID) === true && $redis->get("esi-fetched:$sID") !== "true" && $sID > "199999") {
			$rtq->setTime($sID, 1);
			$count++;
		}
	}
	if ($count > 0) Util::out("Bumped $count api verified active ids in $rtqName");

	return $rtqName;
}
