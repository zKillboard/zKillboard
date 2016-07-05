<?php

global $mdb, $redis, $baseAddr, $fullAddr;

$page = 1;
$pageTitle = '';
$pageType = 'index';
$requestUriPager = '';

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
