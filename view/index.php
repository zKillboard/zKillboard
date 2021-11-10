<?php

global $mdb, $redis;

$page = 1;
$pageTitle = '';
$pageType = 'index';
$requestUriPager = '';

$topPoints = array();
$topIsk = json_decode($redis->get('zkb:TopIskShips'), true);
$topIskShips = json_decode($redis->get('zkb:TopIskShips'), true);
$topIskStructures = json_decode($redis->get('zkb:TopIskStructures'), true);
$topSpecialLosses = json_decode($redis->get('zkb:TopSpecialLosses'), true);

$sponsored = json_decode($redis->get('zkb:sponsored'), true);
$topPods = array();

$top = array();
$top[] = getTop('Top Characters', 'characterID');
$top[] = getTop('Top Corporations', 'corporationID');
$top[] = getTop('Top Alliances', 'allianceID');
$top[] = getTop('Top Ships', 'shipTypeID');
$top[] = getTop('Top Systems', 'solarSystemID');
$top[] = getTop('Top Locations', 'locationID');

$trackedItems = json_decode($redis->get("zkb:ttlc:items:index"), true);

// get latest kills
$kills = Kills::getKills(array('cacheTime' => 60, 'limit' => 50));

// Collect active PVP stats
$activePvP = json_decode($redis->get('zkb:activePvp'));

$app->render('index.html', array('topPods' => $topPods, 'topIsk' => $topIsk, 'topIskShips' => $topIskShips, 'topIskStructures' => $topIskStructures, 'topPoints' => $topPoints, 'topSpecialLosses' => $topSpecialLosses, 'topKillers' => $top, 'kills' => $kills, 'page' => $page, 'pageType' => $pageType, 'pager' => true, 'pageTitle' => $pageTitle, 'requestUriPager' => $requestUriPager, 'activePvP' => $activePvP, 'entityID' => '*', 'trackedItems' => $trackedItems, 'topDonators' => json_decode($redis->get("zkb:topDonators"), true), 'sponsored' => $sponsored));

function getTop($title, $type)
{
    global $redis;

    $key = "zkb:Cache:$title:$type";
    $retVal = json_decode($redis->get($key));
    if ($retVal != null) {
        return $retVal;
    }
    return [];
}
