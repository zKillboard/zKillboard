<?php

require_once "../init.php";

$key = "zkb:topShipsByLossCalc";
if ($redis->get($key) == true && $redis->get("zkb:topKillsByShip") != null) exit();
if ($mdb->findDoc("statistics", ['reset' => true]) !== null) exit();
if ($mdb->findDoc("statistics", ['calcAlltime' => true]) !== null) exit();

MongoCursor::$timeout = -1;

$array = [];

$types = $mdb->find("information", ['type' => 'typeID']);
foreach ($types as $type) {
    if (!isset($type['groupID'])) continue;
    $categoryID = Info::getInfoField('groupID', $type['groupID'], 'categoryID');
    if ($categoryID != 6) continue;
    $p = ['isVictim' => true, 'shipTypeID' => $type['id'], 'cacheTime' => 0];
    $result = Stats::getTopIsk($p, true, true);
    if (sizeof($result) == 0) continue;
    $kill = array_shift($result);
    $array[$kill['zkb']['totalValue']] = $kill;
    $killID = $kill['killID'];
}

krsort($array);

$redis->set("zkb:topKillsByShip", json_encode($array));
$redis->setex($key, 86400, true);
