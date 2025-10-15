<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();

MongoCursor::$timeout = -1;

// sets the maximum of number of large queries executing simultenously
$minute = date("Hi");
$modulus = date("i") % 4;

// switch between larger and smaller sets every other minute
$order = date("i") % 2;
if ($order < 1) $order = -1;

do {
    $rows = $mdb->find("statistics", ['calcAlltime' => true, 'reset' => ['$ne' => true]], ['shipsDestroyed' => $order], 1000);

    if (sizeof($rows) == 0) exit();
    foreach ($rows as $row) {
        if ($minute != date("Hi")) break;
        if ($row['type'] == "label" || $row['id'] == 0) {
            $mdb->set("statistics", $row, ['calcAlltime' => false]);
            continue;
        }

        $highCountKey = "zkb:calcAlltime:highcount:$modulus";
        $highCountKeySet = false;
        $key = "zkb:calcAlltime:" . $row['type'] . ":" . $row['id'];
        $i = $row['type'] . " " . $row['id'] . " : " . $row['shipsDestroyed'];
        try {
            if ($row["shipsDestroyed"] >= 100000) {
                if ($redis->set($highCountKey, "true", ['nx', 'ex' => 80000]) !== true) continue;
                $highCountKeySet = true;
            }

            //Util::out("calcTop $i");

            calcTop($row, $i);
        } finally {
            if ($row["shipsDestroyed"] >= 100000 && $highCountKeySet) $redis->del($highCountKey);
        }
    }
    sleep(1);
} while ($minute == date("Hi"));

function calcTop($row, $i)
{
    global $mdb;

    if (@$row['id'] == 0 || @$row['type'] == null) {
        $mdb->removeField('statistics', $row, 'calcAlltime');
        return;
    }
    $mdb->set("statistics", $row, ['calcAlltime' => $mdb->now()]);

    $dqed = $mdb->findField('information', 'disqualified', ['type' => $row['type'], 'id' => $row['id']]);
    if ($dqed) {
        $mdb->set('statistics', ['_id' => $row['_id']], ['topAllTime' => [], 'topIskKills' => [], 'allTimeSum' => 0, 'nextTopRecalc' => 0, 'calcAlltime' => false]);
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

    $inc = min(11111, ceil($currentSum * 0.01));
    $nextTopRecalc = $currentSum + $inc;

    $mdb->set('statistics', ['_id' => $row['_id']], ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc, 'calcAlltime' => false]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
