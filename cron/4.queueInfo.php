<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser;

$queueInfo = new RedisQueue('queueInfo');
$queueSocial = $beSocial == true ? new RedisQueue('queueSocial') : null;
$queueStats = new RedisQueue('queueStats');
$queueRedisQ = $redisQAuthUser != null ? new RedisQueue('queueRedisQ') : null;
$statArray = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->llen('queueInfo') > 10) $redis->sort('queueInfo', ['sort' => 'desc', 'out' => 'queueInfo']);
    $killID = $queueInfo->pop();

    if ($killID != null) {
        updateInfo($killID);
        updateStatsQueue($killID);

        if ($queueSocial != null) {
            $queueSocial->push($killID);
        }
        if ($queueRedisQ != null) {
            $queueRedisQ->push($killID);
        }
        $mdb->set("killmails", ['killID' => (int) $killID], ['processed' => true]);
    }
}

function updateStatsQueue($killID)
{
    global $mdb, $statArray, $queueStats;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID, 'cacheTime' => 3600]);
    if ($kill['npc'] == true) {
        return;
    }
    $involved = $kill['involved'];
    $sequence = $kill['sequence'];

    // solar system
    addToStatsQueue('solarSystemID', $kill['system']['solarSystemID'], $sequence);
    addToStatsQueue('regionID', $kill['system']['regionID'], $sequence);
    if (isset($kill['locationID'])) {
        addToStatsQueue('locationID', $kill['locationID'], $sequence);
    }

    foreach ($involved as $inv) {
        foreach ($statArray as $stat) {
            if (isset($inv[$stat])) {
                addToStatsQueue($stat, $inv[$stat], $sequence);
            }
        }
    }
}

function addToStatsQueue($type, $id, $sequence)
{
    global $queueStats, $mdb;

    $arr = ['type' => $type, 'id' => $id, 'sequence' => $sequence];
    $queueStats->push($arr);
}

function updateInfo($killID)
{
    global $mdb, $debug, $crestServer;

    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);

    foreach ($killmail['involved'] as $entity) {
        updateEntity($killID, $entity);
    }
    updateItems($killID);
}

function updateItems($killID)
{
    $itemQueue = new RedisQueue('queueItemIndex');
    $itemQueue->push($killID);
}

function updateEntity($killID, $entity)
{
    global $mdb, $debug;
    $types = ['characterID', 'corporationID', 'allianceID', 'factionID'];

    foreach ($types as $type) {
        $id = (int) @$entity[$type];
        if ($id < 1) continue;

        $info = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
        if ($info != null) continue;

        $name = "$type $id";
        $row = ['type' => $type, 'id' => $id, 'name' => $name]; 

        $mdb->insert('information', $row);
        Util::out("Added $type: $name");
    }
}
