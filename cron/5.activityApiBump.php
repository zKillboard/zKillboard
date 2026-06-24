<?php

if (date("i") % 5 !== 0) exit();

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

bumpRecentCharacters();
bumpRecentCorporations();

function bumpRecentCharacters()
{
	global $redis, $mdb;

	foreach (getRecentActivityIDs("recentKillmailActivity:char:") as $charID) {
		if ($charID <= 199999) continue;
		if (wasRecentlyFetched($charID)) continue;

		$mdb->getCollection("scopes")->updateOne(
			[
				'characterID' => $charID,
				'scope' => 'esi-killmails.read_killmails.v1',
				'oauth2' => true,
			],
			[
				'$set' => ['nextCheck' => 1],
			]
		);
	}
}

function bumpRecentCorporations()
{
	global $redis, $mdb;

	foreach (getRecentActivityIDs("recentKillmailActivity:corp:") as $corpID) {
		if ($corpID <= 1999999) continue;
		if (wasRecentlyFetched($corpID)) continue;

		$mdb->getCollection("scopes")->updateMany(
			[
				'corporationID' => $corpID,
				'scope' => 'esi-killmails.read_corporation_killmails.v1',
			],
			[
				'$set' => ['nextCheck' => 1],
			]
		);
	}
}

function getRecentActivityIDs($prefix)
{
	global $redis;

	$ids = [];
	foreach ($redis->keys("$prefix*") as $key) {
		$id = (int) str_replace($prefix, "", $key);
		if ($id > 0) $ids[$id] = true;
	}

	return array_keys($ids);
}

function wasRecentlyFetched($id)
{
	global $redis;

	return $redis->ttl("esi-fetched:$id") > 0;
}
