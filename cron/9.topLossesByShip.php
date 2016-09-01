<?php

require_once "../init.php";

$key = "zkb:topShipsByLossCalc";
if ($redis->get($key) == true && $redis->get("zkb:topKillsByShip") != null) exit();

$array = [];

$types = $mdb->find("information", ['type' => 'typeID']);
foreach ($types as $type) {
    $categoryID = Info::getInfoField('groupID', $type['groupID'], 'categoryID');
    if ($categoryID != 6) continue;
    $p = ['isVictim' => true, 'shipTypeID' => $type['id'], 'cacheTime' => 0];
    $result = Stats::getTopIsk($p);
    if (sizeof($result) == 0) continue;
    $kill = array_shift($result);
    $array[$kill['zkb']['totalValue']] = $kill;
    $killID = $kill['killID'];
    Util::out($type['name'] . ' ' . $kill['zkb']['totalValue'] . " $killID");
}

krsort($array);

$redis->set("zkb:topKillsByShip", json_encode($array));
$redis->setex($key, 86400, true);
