<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser, $killBotWebhook;

$queueInfo = new RedisQueue('queueInfo');
$queueApiCheck = new RedisQueue('queueApiCheck');
$queueSocial = new RedisQueue('queueSocial');
$queueStats = new RedisQueue('queueStats');
$queueRedisQ = new RedisQueue('queueRedisQ');
$queuePublish = new RedisQueue('queuePublish');
$statArray = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueInfo->pop();

    if ($killID != null) {
        updateInfo($killID);
        updateStatsQueue($killID);

        $queueSocial->push($killID);
        $queueRedisQ->push($killID);
        $queuePublish->push($killID);
        $queueApiCheck->push($killID);

        $mdb->set("killmails", ['killID' => $killID], ['processed' => true]);
        addActivity($killID);
    } else if ($mt != 0) break;
    else sleep(1);
}

function addActivity($killID)
{
    global $mdb;

    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    $catID = Info::getInfoField("groupID", $killmail['vGroupID'], 'categoryID');
    if ($catID != 6) return;

    $dttm = $killmail['dttm'];
    $day = (int) date('w', $dttm->toDateTime()->getTimestamp());
    $hour = (int) date('H', $dttm->toDateTime()->getTimestamp());
    foreach ($killmail['involved'] as $i) {
        foreach ($i as $entity=>$id) {
            if (!in_array($entity, ['characterID', 'corporationID', 'allianceID'])) continue;
            if ($entity == 'corporationID' && $id <= 1999999) continue;
            addActivityRow($id, $day, $hour, $killmail['killID'], $dttm);
        }
    }
    addActivityRow(@$killmail['locationID'],  $day, $hour, $killmail['killID'], $dttm);
    addActivityRow(@$killmail['system']['solarSystemID'],  $day, $hour, $killmail['killID'], $dttm);
}

function addActivityRow($id, $day, $hour, $killID, $dttm)
{
    global $mdb;

    if ($id < 0) return;

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

    foreach ($kill['labels'] as $label) {
        addToStatsQueue("label", $label, $sequence);
    }
    addToStatsQueue("label", 'all', $sequence);
}

function addToStatsQueue($type, $id, $sequence)
{
    global $queueStats, $mdb, $redis;

    $redis->sadd("queueStatsSet", "$type:$id");
    $cacheKey = str_replace("shipType", "ship", str_replace("solarS", "s", str_replace("ID", "", "$type:$id")));
    $redis->sadd("queueCacheTags", "killlist:$cacheKey");
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
        }

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
