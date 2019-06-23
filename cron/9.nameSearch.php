<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

global $mdb, $redis;

$key = "zkb:autocomplete";
if ($redis->get($key) == "true") {
    exit();
}


$charsRTQ = new RedisTimeQueue("zkb:characterID", 86400);
$corpsRTQ = new RedisTimeQueue("zkb:corporationID", 86400);
$allisRTQ = new RedisTimeQueue("zkb:allianceID", 86400);

$types = [
    "allianceID",
    "corporationID",
    "characterID",
];

foreach ($types as $type) {
    $redis->del("s:search:$type");
    $toMove = [];

    $entities = $mdb->getCollection('information')->find(['type' => $type]);
    $tickers = [];

    $values = [];
    foreach ($entities as $entity) {
        $id = $entity['id'];
        $name = @strtolower(trim($entity['name']));
        if (isset($entity['ticker'])) $tickers[$id] = $entity['ticker'];
        if ($name != '') $values[$id] = $name;
    }
    asort($values);

    foreach ($values as $id => $name) {
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
                $flag = $tickers[$id];
                break;
            case 'allianceID':
                $allisRTQ->add($id);
                $flag = $tickers[$id];
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
            addSearch($setKey, $name, $id);
        }
        if (strlen($flag) > 0) {
            $setKey = "s:search:$type:flag";
            $toMove[$setKey] = true;
            addSearch($setKey, $flag, $id);
        }
    }

    foreach ($toMove as $setKey => $set) {
        $newName = substr($setKey, 2);
        $redis->rename($setKey, substr($setKey, 2));
    }
}

$redis->setex($key, 10800, "true");

function addSearch($setKey, $name, $id)
{
    global $redis;

    $name = strtolower($name);
    $len = min(strlen($name), 99);
    for ($i = $len; $i > 0; $i--) {
        $sub = substr($name, 0, $i);
        $arr = unserialize($redis->hget($setKey, $sub));
        if ($arr == null) $arr = [];
        if (sizeof($arr) > 10) break;
        $arr[] = "$name\x00$id";
        $redis->hset($setKey, $sub, serialize($arr));
    }
}
