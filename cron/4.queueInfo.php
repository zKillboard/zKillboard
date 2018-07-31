<?php

pcntl_fork();
pcntl_fork();
pcntl_fork();

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser;

$queueInfo = new RedisQueue('queueInfo');
$queuePublish = new RedisQueue('queuePublish');
$queueApiCheck = new RedisQueue('queueApiCheck');
$queueSocial = new RedisQueue('queueSocial');
$queueStats = new RedisQueue('queueStats');
$queueRedisQ = new RedisQueue('queueRedisQ');
$statArray = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueInfo->pop();

    if ($killID != null) {
        updateInfo($killID);
        updateStatsQueue($killID);

        $queueSocial->push($killID);
        $queueRedisQ->push($killID);
        $queueApiCheck->push($killID);
        $queuePublish->push($killID);

        $mdb->set("killmails", ['killID' => $killID], ['processed' => true]);
    } else sleep(1);
}

function updateStatsQueue($killID)
{
    global $mdb, $statArray, $queueStats;

    $kill = $mdb->findDoc('killmails', ['killID' => $killID]);
    $involved = $kill['involved'];
    $sequence = $kill['sequence'];

    // solar system
    addToStatsQueue('solarSystemID', $kill['system']['solarSystemID'], $sequence);
    addToStatsQueue('constellationID', $kill['system']['constellationID'], $sequence);
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
    global $queueStats, $mdb, $redis;

    $redis->sadd("queueStatsSet", "$type:$id");
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
    global $mdb, $debug, $redis;
    $types = ['characterID', 'corporationID', 'allianceID'];

    foreach ($types as $type) {
        $id = (int) @$entity[$type];
        if ($id < 1) continue;

        $info = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
        if ($info != null) continue;

        $defaultName = "$type $id";
        $row = ['type' => $type, 'id' => $id];

        $mdb->insertUpdate('information', $row, ['name' => $defaultName]);
        $rtq = new RedisTimeQueue("zkb:$type", 86400);
        $rtq->add($id);

        if ($killID < ($redis->get('zkb:topKillID') - 10000)) continue;

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
