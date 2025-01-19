<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();
if ($mdb->findDoc("statistics", ['reset' => true]) != null) exit();

MongoCursor::$timeout = -1;

$minute = date("Hi");

do {
    $row = $mdb->findDoc("statistics", ['calcAlltime' => true], ['shipsDestroyed' => 1]);
    if ($row == null) exit();

    $key = "zkb:calcAlltime:" . $row['type'] . ":" . $row['id'];
    try {
        if ($redis->set($key, "true", ['nx', 'ex' => 80000]) !== true) exit();
        calcTop($row);
    } finally {
        $redis->del($key);
    }
} while ($minute == date("Hi"));

function calcTop($row)
{
    global $mdb;

    if (@$row['id'] == 0 || @$row['type'] == null) {
        $mdb->removeField('statistics', $row, 'calcAlltime');
        return;
    }

    $currentSum = (int) @$row['shipsDestroyed'];

    $parameters = [$row['type'] => $row['id']];
    $parameters['limit'] = 100;
    $parameters['kills'] = true;
    $parameters['labels'] = 'pvp';

    $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters, true, false));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters, true, false));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters, true, false));
    $topLists[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters, true, false));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters, true, false));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters, true, false));

    $p = $parameters;
    $p['limit'] = 6;
    $topKills = array_keys(Stats::getTopIsk($p));

    $nextTopRecalc = ceil($currentSum * 1.01);

    $mdb->set('statistics', ['_id' => $row['_id']], ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc, 'calcAlltime' => false]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
