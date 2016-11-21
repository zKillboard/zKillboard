<?php

require_once '../init.php';

global $redis;

$time = time();
$time = $time - ($time % 900);
$key = "zkb:everyFifteen:$time";
if ($redis->get($key) == true) {
    exit();
}

$p = array();
$numDays = 7;
$p['limit'] = 10;
$p['pastSeconds'] = $numDays * 86400;
$p['kills'] = true;

$redis->set('zkb:TopIsk', json_encode(Stats::getTopIsk(array('pastSeconds' => ($numDays * 86400), 'categoryID' => 6, 'limit' => 5))));

getTop('Top Characters', 'characterID');
getTop('Top Corporations', 'corporationID');
getTop('Top Alliances', 'allianceID');
getTop('Top Ships', 'shipTypeID');
getTop('Top Systems', 'solarSystemID');
getTop('Top Locations', 'locationID');

// Collect active PVP stats
$types = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'regionID'];
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
$redis->set('RC:activePvp', json_encode($activePvP));

// Some cleanup
$redis->keys('*'); // Helps purge expired ttl's
$redis->setex($key, 900, true);

function getTop($title, $type)
{
    global $redis;

    $key = "RC:Cache:$title:$type";
    $retVal = [];

    $ids = $redis->zRange("tq:ranks:weekly:$type", 0, 10);
    if (sizeof($ids) == 0) {
        return [];
    }
    foreach ($ids as $id) {
        $retVal[] = [$type => $id, 'kills' => $redis->zScore("tq:ranks:weekly:$type:shipsDestroyed", $id)];
    }
    Info::addInfo($retVal);
    $retVal = ['type' => str_replace('ID', '', $type), 'title' => $title, 'values' => $retVal];
    $redis->set($key, json_encode($retVal));

    return $retVal;
}
