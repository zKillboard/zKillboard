<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();

$minute = date("Hi");
while ($minute == date("Hi")) {
    $rows = $mdb->find("statistics", ['calcAlltime' => true, 'reset' => ['$ne' => true]], ['shipsDestroyed' => 1], 1);
    if (sizeof($rows) == 0) {
        sleep(1);
    } else {
        $row = $rows[0];

        if ($row['type'] == "label" || $row['id'] == 0) {
            $mdb->set("statistics", $row, ['calcAlltime' => false]);
            continue;
        }

        $i = $row['type'] . " " . $row['id'] . " : " . $row['shipsDestroyed'];
        $now = time();
        calcTop($row);
        //Util::out("calcTop $i -> " . (time() - $now) . " seconds");
    }
}

function calcTop($row)
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
