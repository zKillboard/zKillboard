<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();

MongoCursor::$timeout = -1;
$minute = date("Hi");
$queueTopAllTime = new RedisQueue("queueTopAllTime");

if (/*$master == true &&*/ $queueTopAllTime->size() == 0) {
    $cursor = $mdb->getCollection("statistics")->find(['calcAlltime' => true])->limit(5000);
    while ($cursor->hasNext()) {
        $row = $cursor->next();
        $queueTopAllTime->push(['type' => $row['type'], 'id' => (int) $row['id']]);
    }
}

while ($queueTopAllTime->size() > 0 && date('Hi') == $minute) {
    $id = $queueTopAllTime->pop();
    if ($id == null) break;
    $row = $mdb->findDoc("statistics", $id);
    calcTop($row);
}
pcntl_wait($status);
pcntl_wait($status);
pcntl_wait($status);

function calcTop($row)
{
    global $mdb;

    if (@$row['id'] == 0 || @$row['type'] == null) return;

    $currentSum = (int) @$row['shipsDestroyed'];
    //Util::out("TopAllTime: " . $row['type'] . ' ' . $row['id'] . ' - ' . $currentSum);

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
    //$p['categoryID'] = 6;
    $topKills = Stats::getTopIsk($p);
    $topKills = array_keys($topKills);

    $nextTopRecalc = ceil($currentSum * 1.01);

    $mdb->set('statistics', $row, ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
