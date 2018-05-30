<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser;

$queueInfo = new RedisQueue('queueInfo');
$queueApiCheck = new RedisQueue('queueApiCheck');
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
        $queueApiCheck->push($killID);
        $mdb->set("killmails", ['killID' => (int) $killID], ['processed' => true]);
    }
}

function updateStatsQueue($killID)
{
    global $mdb, $statArray, $queueStats;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID, 'cacheTime' => 3600]);
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
    global $mdb, $debug;

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

        $defaultName = "$type $id";
        $row = ['type' => $type, 'id' => $id, 'name' => $defaultName]; 

        $mdb->insert('information', $row);
        $rtq = new RedisTimeQueue("zkb:$type", 86400);
        $rtq->add($id);

        $iterations = 0;
        do {
            $info = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
            $name = @$info['name'];
            if ($name == $defaultName) {
                sleep(1);
                $iterations++;
            }
        } while ($name == $defaultName && $iterations <= 20);
    }
}
