<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->llen("queueProcess") > 100) exit();

$date = date('Ymd');
$redisKey = "tq:topAllTime:$date";
$queueTopAlltime = new RedisQueue('queueTopAlltime');
if ($redis->get($redisKey) != true) {
    $queueTopAlltime->clear();
    $iter = $mdb->getCollection('statistics')->find([], ['months' => 0, 'groups' => 0])->sort(['type' => 1, 'id' => 1]);
    while ($row = $iter->next()) {
        if ($row['type'] == 'characterID') {
            continue;
        }
        if ($row['type'] == 'locationID') {
            continue;
        }

        $allTimeSum = (int) @$row['allTimeSum'];
        $shipsDestroyed = (int) @$row['shipsDestroyed'];
        $nextTopRecalc = floor($allTimeSum * 1.01);
        if ($shipsDestroyed == 0 || $shipsDestroyed < $nextTopRecalc) continue;

        $queueTopAlltime->push($row['_id']);
    }
}

$redis->setex($redisKey, 86400, true);
$timer = new Timer();

while ($id = $queueTopAlltime->pop()) {
    $row = $mdb->findDoc('statistics', ['_id' => $id]);
    calcTop($row);
    if ($timer->stop() > 60000) {
        exit();
    }
}

function calcTop($row)
{
    global $mdb;

    $timer = new Timer();
    $currentSum = (int) @$row['shipsDestroyed'];

    $parameters = [$row['type'] => $row['id']];
    $parameters['limit'] = 10;
    $parameters['kills'] = true;

    $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters));
    $topLists[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters));

    $mdb->set('statistics', $row, ['topAllTime' => $topLists, 'allTimeSum' => $currentSum]);
    if ($timer->stop() > 60000) {
        exit();
    }
}
