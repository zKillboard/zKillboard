<?php

require_once "../init.php";

$fetched = $redis->get("zkb:zkbFeed");
if ($fetched == true) exit();

// Build the feeds from the config
global $characters, $corporations, $alliances;

foreach ($characters as $character) doFetch('character', $character);
foreach ($corporations as $corporation) doFetch('corporation', $corporation);
foreach ($alliances as $alliance) doFetch('alliance', $alliance);

function doFetch($type, $id)
{
	global $redis;

		$lastFetchedID = (int) $redis->get("zkb:lastFetchedID:$type:id");
	do {
		$url = "https://zkillboard.com/api/{$type}ID/$id/orderDirection/asc/afterKillID/$lastFetchedID/no-items/no-attackers/";

		$raw = Util::getData($url);
		$json = json_decode($raw, true);
		foreach ($json as $kill)
		{
			$killID = (int) $kill['killID'];
			$hash = $kill['zkb']['hash'];
			$lastFetchedID = max($lastFetchedID, $killID);
			saveKill($killID, $hash);
		}
		$newKills = sizeof($json);
	} while ($newKills > 0); 
	$redis->set("zkb:lastFetchedID:$type:id", $lastFetchedID);
}

$redis->setex("zkb:zkbFeed", 3601, true);

function saveKill($killID, $hash)
{
	global $mdb;

	try {
		$mdb->save("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'zkillboard.com']);
	} catch (Exception $ex) {
		if ($ex->getCode() == 11000) return;
		throw $ex;
	}
}
