<?php

global $mdb, $redis;

$page = 1;
$pageTitle = '';
$pageType = 'index';
$requestUriPager = '';
$serverName = $_SERVER['SERVER_NAME'];
global $baseAddr, $fullAddr;
if ($serverName != $baseAddr) {
	$numDays = 7;
	$p = Subdomains::getSubdomainParameters($serverName);
	$page = max(1, min(25, $page));
	$p['page'] = $page;

	$columnName = key($p);
	$id = (int) reset($p);

	if (sizeof($p) <= 1) {
		$app->redirect($fullAddr, 302);
	}

	$topPoints = array();
	$topPods = array();

	$p['kills'] = true;
	$p['pastSeconds'] = ($numDays * 86400);

	$top = array();
	$top[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
	$top[] = ($columnName != 'corporationID' ? Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p)) : array());
	$top[] = ($columnName != 'corporationID' && $columnName != 'allianceID' ? Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p)) : array());
	$top[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
	$top[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));
	$top[] = Info::doMakeCommon('Top Locations', 'locationID', Stats::getTop('locationID', $p));

	$requestUriPager = str_replace('ID', '', $columnName)."/$id/";

	$p['limit'] = 5;
	$topIsk = Stats::getTopIsk($p);
	unset($p['pastSeconds']);
	unset($p['kills']);

	// get latest kills
	$killsLimit = 50;
	$p['limit'] = $killsLimit;
	$kills = Kills::getKills($p);

	$kills = Kills::mergeKillArrays($kills, array(), $killsLimit, $columnName, $id);

	Info::addInfo($p);
	$pageTitle = array();
	foreach ($p as $key => $value) {
		if (strpos($key, 'Name') !== false) {
			$pageTitle[] = $value;
		}
	}
	$pageTitle = implode(',', $pageTitle);
	$pageType = 'subdomain';
} else {
	$topPoints = array();
	$topIsk = json_decode($redis->get("RC:TopIsk"), true);
	$topPods = array();

	$top = array();
	$top[] = getTop("Top Characters", "characterID");
	$top[] = getTop("Top Corporations", "corporationID"); 
	$top[] = getTop("Top Alliances", "allianceID");
	$top[] = getTop("Top Ships", "shipTypeID");
	$top[] = getTop("Top Systems", "solarSystemID");
	$top[] = getTop("Top Locations", "locationID"); 


	// get latest kills
	$kills = Kills::getKills(array('cacheTime' => 300, 'limit' => 50));

	// Collect active PVP stats
	$types = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'regionID'];
	$activePvP = json_decode($redis->get("RC:activePvp"));
	if ($activePvP === null) {
		$activePvP = [];
		foreach ($types as $type) {
			$result = Stats::getDistinctCount($type, []);
			if ($result <= 1) {
				continue;
			}
			$type = str_replace('ID', '', $type);
			if ($type == 'shipType') {
				$type = 'Ship';
			} elseif ($type == 'solarSystem') {
				$type = 'System';
			} else {
				$type = ucfirst($type);
			}
			$type = $type.'s';
			$row['type'] = $type;
			$row['count'] = $result;
			$activePvP[] = $row;
		}
		$totalKills = $mdb->count('oneWeek');
		$activePvP[] = ['type' => 'Total Kills', 'count' => "$totalKills"];
		$redis->setex("RC:activePvp", 900, json_encode($activePvP));
	}

}

$app->render('index.html', array('topPods' => $topPods, 'topIsk' => $topIsk, 'topPoints' => $topPoints, 'topKillers' => $top, 'kills' => $kills, 'page' => $page, 'pageType' => $pageType, 'pager' => true, 'pageTitle' => $pageTitle, 'requestUriPager' => $requestUriPager, 'activePvP' => $activePvP));

function getTop($title, $type) {
	global $redis;

	$key = "RC:Cache:$title:$type";
	$retVal = json_decode($redis->get($key));
	if ($retVal != null) return $retVal;
	$retVal = [];

	$ids = $redis->zRange("tq:ranks:weekly:$type", 0, 10);
	if (sizeof($ids) == 0) return [];
	foreach ($ids as $id) {
		$retVal[] = [$type => $id, 'kills' => $redis->zScore("tq:ranks:weekly:$type:shipsDestroyed", $id)];
	}
	Info::addInfo($retVal);
	$retVal = ['type' => str_replace("ID", "", $type), 'title' => $title, 'values' => $retVal];
	$redis->setex($key, 900, json_encode($retVal));
	return $retVal;
	//return ['type' => str_replace("ID", "", $type), 'title' => $title, 'values' => $retVal];
}
