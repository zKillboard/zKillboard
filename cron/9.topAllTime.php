<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();

MongoCursor::$timeout = -1;
$minute = date('Hi');
$redisKey = "tq:topAllTime";
$queueTopAlltime = new RedisQueue('queueTopAlltime');
if ($redis->get($redisKey) === false) {
    $redis->del('queueTopAlltime');
}
if ($redis->get($redisKey) != "true" && $queueTopAlltime->size() == 0) {
    $iter = $mdb->getCollection('statistics')->find(['calcAlltime' => true])->sort(['shipsDestroyed' => 1]);
    while ($row = $iter->next()) {
        if (@$row['reset'] == true) continue;
        $queueTopAlltime->push($row['_id']);
    }
    $redis->setex($redisKey, 3600, "true");
}

while ($minute == date('Hi') && ($id = $queueTopAlltime->pop())) {
    $row = $mdb->findDoc('statistics', ['_id' => $id]);
    calcTop($row);
}

function calcTop($row)
{
    global $mdb;

    if ($row['id'] == 0 || $row['type'] == null) return;

    $currentSum = (int) @$row['shipsDestroyed'];
    Util::out("TopAllTime: " . $row['type'] . ' ' . $row['id'] . ' - ' . $currentSum);

    $parameters = [$row['type'] => $row['id']];
    $parameters['limit'] = 100;
    $parameters['kills'] = true;

    $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters, true, false));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters, true, false));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters, true, false));
    $topLists[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters, true, false));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters, true, false));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters, true, false));

    $p = $parameters;
    $p['limit'] = 6;
    $p['categoryID'] = 6;
    $topKills = Stats::getTopIsk($p);
    $topKills = array_keys($topKills);

    $nextTopRecalc = ceil($currentSum * 1.01);

    $mdb->set('statistics', $row, ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
