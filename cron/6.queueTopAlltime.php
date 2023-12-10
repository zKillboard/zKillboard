<?php

$master = (pcntl_fork() > 0); 

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();
$todaysKey = "zkb:topAllTimeComplete:" . date('Ymd', time() - 36000);

MongoCursor::$timeout = -1;
$minute = date("Hi");
$queueTopAllTime = new RedisQueue("queueTopAllTime");

if ($master == true && $redis->get($todaysKey) != "true" && $queueTopAllTime->size() == 0 && $mdb->findDoc("statistics", ['reset' => true]) == null) {
    Util::out("Populating top all time queue");
    $cursor = $mdb->getCollection("statistics")->find(['calcAlltime' => true])->limit(5000);
    while ($cursor->hasNext()) {
        $row = $cursor->next();
        $queueTopAllTime->push(['type' => $row['type'], 'id' => (int) $row['id']]);
    }
    $redis->setex($todaysKey, 86400, "true");
}

while ($queueTopAllTime->size() > 0 && date('Hi') == $minute) {
    $id = $queueTopAllTime->pop();
    if ($id == null) break;
    $row = $mdb->findDoc("statistics", $id);
    calcTop($row);
}

$a = 0;
while (pcntl_wait($a) > 0) sleep(1);
exit();

function calcTop($row)
{
    global $mdb;

    if (@$row['id'] == 0 || @$row['type'] == null) {
        $mdb->removeField('statistics', $row, 'calcAlltime');
        return;
    }
    Util::out("Top All Time calculating: " . $row['type'] . " " . $row['id']);

    $currentSum = (int) @$row['shipsDestroyed'];

    $parameters = [$row['type'] => $row['id']];
    $parameters['limit'] = 100;
    $parameters['kills'] = true;
    $parameters['npc'] = false;
    //$parameters['labels'] = 'pvp';

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
