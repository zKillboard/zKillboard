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
$redis->set('zkb:activePvp', json_encode($activePvP));

// Get unique counts
$types = $mdb->getCollection("information")->distinct('type');
foreach ($types as $type) {
    $total = $mdb->count("information", ['type' => $type]);
    $redis->setex("zkb:unique:$type", 86400, $total);
}

// Some cleanup
$redis->keys('*'); // Helps purge expired ttl's
$redis->setex($key, 900, true);

$redis->set("tobefetched", $mdb->count("crestmails", ['processed' => false]));

function getTop($title, $type)
{
    global $redis;

    $key = "zkb:Cache:$title:$type";
    $retVal = [];

    $ids = $redis->zRange("tq:ranks:weekly:$type", 0, 20);
    if (sizeof($ids) == 0) {
        return [];
    }
    foreach ($ids as $id) {
        if (sizeof($retVal) >= 10) break;
        if ($type == "corporationID" && $id <= 1999999) continue;
        $retVal[] = [$type => $id, 'kills' => $redis->zScore("tq:ranks:weekly:$type:shipsDestroyed", $id), 'score' => $redis->zScore("tq:ranks:weekly:$type", $id)];
    }
    Info::addInfo($retVal);
    $retVal = ['type' => str_replace('ID', '', $type), 'title' => $title, 'values' => $retVal];
    $redis->set($key, json_encode($retVal));

    return $retVal;
}
