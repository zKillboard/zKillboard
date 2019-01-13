<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

global $mdb, $redis;

$key = "zkb:autocomplete:" . date('YmdH');
if ($redis->get($key) == true) {
    exit();
}

$entities = $mdb->getCollection('information')->find()->sort(['_id' => -1]);
$entities->timeout(0);

$toMove = [];

$charsRTQ = new RedisTimeQueue("zkb:characterID", 86400);
$corpsRTQ = new RedisTimeQueue("zkb:corporationID", 86400);
$allisRTQ = new RedisTimeQueue("zkb:allianceID", 86400);

foreach ($entities as $entity) {
    $type = $entity['type'];
    $id = $entity['id'];
    $name = @$entity['name'];

    $isShip = false;
    $flag = '';
    switch ($type) {
        case 'locationID':
        case 'warID':
            continue;
        case 'characterID':
            $charsRTQ->add($id);
            break;
        case 'corporationID':
            $corpsRTQ->add($id);
            $flag = @$entity['ticker'];
            break;
        case 'allianceID':
            $allisRTQ->add($id);
            $flag = @$entity['ticker'];
            break;
        case 'typeID':
            if ($mdb->exists('killmails', ['involved.shipTypeID' => $id])) {
                $flag = strtolower($name);
            }
            if (@$entity['published'] != true && $flag == '') {
                continue;
            }
            $isShip = $flag != '';
            break;
        case 'solarSystemID':
            $regionID = Info::getInfoField('solarSystemID', $id, 'regionID');
            $regionName = Info::getInfoField('regionID', $regionID, 'name');
            $name = "$name ($regionName)";
            break;
    }

    if ($name == '') continue;
    if (strpos($name, '!') !== false) continue;

    if (!$isShip) {
        $setKey = "s:search:$type";
        $toMove[$setKey] = true;
        $redis->zAdd($setKey, 0, trim(strtolower($name))."\x00$id");
        //$mdb->insertUpdate("search", ['type' => $type, 'name' => strtolower($name)], ['id' => $id, 'dttm' => $mdb->now()]);
    }
    if (strlen($flag) > 0) {
        $setKey = "s:search:$type:flag";
        $toMove[$setKey] = true;
        $redis->zAdd($setKey, 0, strtolower("$flag\x00$id"));
        //$mdb->insertUpdate("search", ['type' => $type . ":flag", 'name' => strtolower($flag)], ['id' => $id, 'dttm' => $mdb->now()]);
    }
}

foreach ($toMove as $setKey => $set) {
    $newName = substr($setKey, 2);
    $redis->rename($setKey, substr($setKey, 2));
}

$redis->setex($key, 3600, true);
