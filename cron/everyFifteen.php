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
    global $redis, $mdb;

    $key = "zkb:Cache:$title:$type";
    $retVal = [];

    // Get top 20 by weekly overall rank from MongoDB
    $cursor = $mdb->getCollection('statistics')->find(
        [
            'type' => $type,
            'ranks.weekly.overall' => ['$exists' => true]
        ],
        [
            'sort' => ['ranks.weekly.overall' => -1],
            'limit' => 20,
            'projection' => ['id' => 1, 'stats.weekly.shipsDestroyed' => 1]
        ]
    );
    
    foreach ($cursor as $row) {
        if (sizeof($retVal) >= 10) break;
        $id = $row['id'];
        if ($type == "corporationID" && $id <= 1999999) continue;
        
        $kills = isset($row['stats']['weekly']['shipsDestroyed']) ? $row['stats']['weekly']['shipsDestroyed'] : 0;
        $retVal[] = [$type => $id, 'kills' => $kills];
    }
    
    if (sizeof($retVal) == 0) {
        return [];
    }
    
    Info::addInfo($retVal);
    $retVal = ['type' => str_replace('ID', '', $type), 'title' => $title, 'values' => $retVal];
    $redis->set($key, json_encode($retVal));
    print_r($retVal);
    exit();

    return $retVal;
}
