<?php

global $mdb, $redis;

$page = 1;
$pageTitle = '';
$pageType = 'index';
$requestUriPager = '';
$serverName = $_SERVER['SERVER_NAME'];

$topPoints = array();
$topIsk = json_decode($redis->get('zkb:TopIsk'), true);
$topPods = array();

$top = array();
$top[] = getTop('Top Characters', 'characterID');
$top[] = getTop('Top Corporations', 'corporationID');
$top[] = getTop('Top Alliances', 'allianceID');
$top[] = getTop('Top Ships', 'shipTypeID');
$top[] = getTop('Top Systems', 'solarSystemID');
$top[] = getTop('Top Locations', 'locationID');

// get latest kills
$kills = Kills::getKills(array('cacheTime' => 60, 'limit' => 50));

// Collect active PVP stats
$activePvP = json_decode($redis->get('RC:activePvp'));

$app->render('index.html', array('topPods' => $topPods, 'topIsk' => $topIsk, 'topPoints' => $topPoints, 'topKillers' => $top, 'kills' => $kills, 'page' => $page, 'pageType' => $pageType, 'pager' => true, 'pageTitle' => $pageTitle, 'requestUriPager' => $requestUriPager, 'activePvP' => $activePvP));

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
