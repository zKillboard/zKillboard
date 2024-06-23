<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

$waitfor = [];
$waitfor[] = bump("recentKillmailActivity:corp:", "tqCorpApiESI", "corporationID");
$waitfor[] = bump("recentKillmailActivity:char:", "tqApiESI", "characterID");

function bump($key, $rtqName, $type) {
	global $redis, $mdb;

	$rtq = new RedisTimeQueue($rtqName, 3600);

	$values = $redis->keys("$key*");
	$count = 0;
	foreach ($values as $sID) {
		$sID = ((int) str_replace($key, "", $sID));
		if ($rtq->isMember($sID) === true && $redis->get("esi-fetched:$sID") !== "true" && $sID > "199999") {
            if ($type == "characterID" && ($mdb->findDoc("scopes", ['characterID' => (int) $sID, 'scope' => "esi-killmails.read_killmails.v1", "oauth2" => true], ['lastFetch' => 1]))) {
                $rtq->setTime($sID, 1);
            }
            else if ($type == "corporationID") $rtq->setTime($sID, 1);
		}
	}

	return $rtqName;
}
