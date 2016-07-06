<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $queueSocial, $redisQAuthUser;

$queueInfo = new RedisQueue('queueInfo');
$queueCleanup = new RedisQueue('queueCleanup');
$queueSocial = $beSocial == true ? new RedisQueue('queueSocial') : null;
$queueStats = new RedisQueue('queueStats');
$queueRedisQ = $redisQAuthUser != null ? new RedisQueue('queueRedisQ') : null;
$killmails = $mdb->getCollection('killmails');
$rawmails = $mdb->getCollection('rawmails');
$information = $mdb->getCollection('information');
$statArray = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

$timer = new Timer();
while ($timer->stop() < 59000) {
    $killID = $queueInfo->pop();

    if ($killID != null) {
        updateInfo($killID);
        updateStatsQueue($killID);

        if ($queueSocial != null) $queueSocial->push($killID);
        if ($queueRedisQ != null) $queueRedisQ->push($killID);
	$queueCleanup->push($killID);
    }
}

function updateStatsQueue($killID)
{
    global $killmails, $statArray, $queueStats;

    $kill = $killmails->findOne(['killID' => $killID]);
    if ($kill['npc'] == true) return;
    $involved = $kill['involved'];
    $sequence = $kill['sequence'];

    // solar system
    addToStatsQueue('solarSystemID', $kill['system']['solarSystemID'], $sequence);
    addToStatsQueue('regionID', $kill['system']['regionID'], $sequence);
    if (isset($kill['locationID'])) addToStatsQueue('locationID', $kill['locationID'], $sequence);

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

    $killmail = $mdb->findDoc('rawmails', ['killID' => $killID]);
    $system = $killmail['solarSystem'];
    $id = (int) $system['id'];
    if ($id == 0) {
        return;
    }
    if ($mdb->count('information', ['type' => 'solarSystemID', 'id' => $id]) == 0) {
        // system doesn't exist in our database yet
        $name = $system['name'];
        $crestSystem = CrestTools::getJSON($system['href']);
        if ($crestSystem == '') {
            exit("no system \o/ $killID $id".$system['href']);
        }

        $ex = explode('/', $crestSystem['constellation']['href']);
        $constID = (int) $ex[4];
        if (!$mdb->exists('information', ['type' => 'constellationID', 'id' => $constID])) {
            $crestConst = CrestTools::getJSON($crestSystem['constellation']['href']);
            if ($crestConst == '') {
                exit();
            }
            $constName = $crestConst['name'];

            $regionURL = $crestConst['region']['href'];
            $ex = explode('/', $regionURL);
            $regionID = (int) $ex[4];

            $mdb->insertUpdate('information', ['type' => 'constellationID', 'id' => $constID], ['name' => $constName, 'regionID' => $regionID]);
            if ($debug) {
                Util::out("Added constellation: $constName");
            }
        }
        $constellation = $mdb->findDoc('information', ['type' => 'constellationID', 'id' => $constID]);
        $regionID = (int) $constellation['regionID'];

        if (!$mdb->exists('information', ['type' => 'regionID', 'id' => $regionID])) {
            $regionURL = "$crestServer/regions/$regionID/";
            $crestRegion = CrestTools::getJSON($regionURL);
            if ($crestRegion == '') {
                exit();
            }

            $regionName = $crestRegion['name'];
            $mdb->insertUpdate('information', ['type' => 'regionID', 'id' => $regionID], ['name' => $regionName]);
            if ($debug) {
                Util::out("Added region: $regionName");
            }
        }
        $mdb->insertUpdate('information', ['type' => 'solarSystemID', 'id' => $id], ['name' => $name, 'regionID' => $regionID, 'secStatus' => ((double) $crestSystem['securityStatus']), 'secClass' => $crestSystem['securityClass']]);
        Util::out("Added system: $name");
    }

    updateItems($killID, $killmail['killTime'], @$killmail['victim']['items']);
    updateEntity($killID, $killmail['victim']);
    foreach ($killmail['attackers'] as $entity) {
        updateEntity($killID, $entity);
    }
}

function updateItems($killID, $killTime, $items)
{
    global $mdb;

    $time = strtotime(str_replace('.', '-', $killTime).' UTC');
    if ($time < (time() - 2419200)) {
        return;
    }

    $dttm = new MongoDate($time);
    foreach ($items as $item) {
        $typeID = (int) $item['itemType']['id'];
        if (!$mdb->exists('itemmails', ['killID' => $killID, 'typeID' => $typeID])) {
            $mdb->insert('itemmails', ['killID' => $killID, 'typeID' => $typeID, 'dttm' => $dttm]);
        }
        if (isset($items['items'])) {
            updateItems($killID, $killTime, $items['items']);
        }
    }
}

function updateEntity($killID, $entity)
{
    global $information, $mdb, $debug;
    $types = ['character', 'corporation', 'alliance', 'faction'];

    for ($index = 0; $index < 4; ++$index) {
        $type = $types[$index];
        if (!isset($entity[$type]['id'])) {
            continue;
        }

        $id = $entity[$type]['id'];
        $name = @$entity[$type]['name'];

        // Look for the current entry
        $query = ['type' => $type.'ID', 'id' => $id]; //, 'killID' => ['$gte' => $killID]];
        if ($mdb->exists('information', $query)) {
            continue;
        }
        unset($query['killID']);
        $row = $mdb->findDoc('information', $query);

        $new = ($row == null);
        if (!isset($row['killID'])) {
            $row['killID'] = 0;
        }
        if ($row != null && $killID <= $row['killID']) {
            echo "continuing..\n";
        }
        if ($row != null && $killID <= $row['killID']) {
            continue;
        }

        $updates = [];
        $updates['name'] = $name;
        $updates['killID'] = $killID;

        for ($subIndex = $index + 1; $subIndex < 4; ++$subIndex) {
            $subType = $types[$subIndex];
            $updates["${subType}ID"] = (int) @$entity[$subType]['id'];
        }
        $mdb->insertUpdate('information', $query, $updates);
        if ($new && $debug) {
            Util::out("Added $type: $name");
        }
    }
}
