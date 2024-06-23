<?php

$master = (bool) (pcntl_fork() > 0);
if (!$master) pcntl_fork();
if (!$master) pcntl_fork();
if (!$master) pcntl_fork();

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser, $killBotWebhook;

$queueInfo = new RedisQueue('queueInfo');
$queueApiCheck = new RedisQueue('queueApiCheck');
$queueSocial = new RedisQueue('queueSocial');
$queueStats = new RedisQueue('queueStats');
$queueRedisQ = new RedisQueue('queueRedisQ');
//$queueDiscord = new RedisQueue('queueDiscord');
$statArray = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueInfo->pop();

    if ($killID != null) {
        updateInfo($killID);
        updateStatsQueue($killID);

        $queueSocial->push($killID);
        $queueRedisQ->push($killID);
        //$queueDiscord->push($killID);
        $queueApiCheck->push($killID);

        $mdb->set("killmails", ['killID' => $killID], ['processed' => true]);
        addActivity($killID);
    } else if (!$master) break;
    else sleep(1);
}

function addActivity($killID)
{
    global $mdb;

    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    $dttm = $killmail['dttm'];
    $day = (int) date('w', $dttm->sec);
    $hour = (int) date('H', $dttm->sec);
    foreach ($killmail['involved'] as $i) {
        foreach ($i as $entity=>$id) {
            if (!in_array($entity, ['characterID', 'corporationID', 'allianceID'])) continue;
            if ($entity == 'corporationID' && $id <= 1999999) continue;
            addActivityRow($id, $day, $hour, $killmail['killID'], $dttm);
        }
    }
}

function addActivityRow($id, $day, $hour, $killID, $dttm)
{
    global $mdb;

    try {
        $mdb->insert("activity", ['id' => $id, 'day' => $day, 'hour' => $hour, 'killID' => $killID, 'dttm' => $dttm]);
    } catch (Exception $ex) {
        // probably a dupe, ignore it
    }
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
        if ($id <= 1) continue;

        $row = ['type' => $type, 'id' => (int) $id];

        $defaultName = "$type $id";
        if ($mdb->count('information', $row) == 0) {
            $insert = $row;
            $insert['name'] = $defaultName;
            try {$mdb->insert('information', $row);} catch (Exception $eex) {} 
        } else if ($type == 'characterID' && $redis->get("zkb:24hourUpdate:$id") != "true") {
            $mdb->removeField("information", ['type' => 'characterID', 'id' => $id, 'corporationID' => ['$exists' => false]], 'lastApiUpdate');
            $mdb->removeField("information", ['type' => 'characterID', 'id' => $id, 'corporationID' => ['$exists' => false]], 'lastAffUpdate');
            $redis->setex("zkb:24hourUpdate:$id", 86400, "true");
        }
        $rtq = new RedisTimeQueue("zkb:$type", 86400);
        $rtq->add($id);

        if ($killID < ((int) $redis->get('zkb:topKillID') - 100000)) continue;

        $iterations = 0;
        do {
            $info = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
            $name = @$info['name'];
            if ($name == $defaultName) {
                if ($redis->get("zkb:updatenow:$type:$id") != "true") {
                    $mdb->removeField("information", ['type' => $type, 'id' => $id], 'lastApiUpdate');
                    $redis->setex("zkb:updatenow:$type:$id", 300, "true");
                }
                sleep(1);
                $iterations++;
            }
        } while ($name == $defaultName && $iterations <= 20);
    }
}
