<?php

$mt = 4; $pids = []; do { $mt--; if (($pid = pcntl_fork()) > 0) $pids[] = $pid; } while ($pid > 0 && $mt > 0); if ($pid > 0) { foreach ($pids as $c) pcntl_waitpid($c, $s); exit(); } $pid = $mt;

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->scard("queueStatsSet") > 1000) exit();

MongoCursor::$timeout = -1;

$minute = date("Hi");

do {
    $rows = $mdb->find("statistics", ['calcAlltime' => true, 'reset' => ['$ne' => true]], ['shipsDestroyed' => 1], 1000);

    if (sizeof($rows) == 0) exit();
    foreach ($rows as $row) {
        if ($minute != date("Hi")) break;
        if ($row['type'] == "label" || $row['id'] == 0) {
            $mdb->set("statistics", $row, ['calcAlltime' => false]);
            continue;
        }

        $highCountKey = "zkb:calcAlltime:highcount";
        $highCountKeySet = false;
        $key = "zkb:calcAlltime:" . $row['type'] . ":" . $row['id'];
        $i = $row['type'] . " " . $row['id'] . " : " . $row['shipsDestroyed'];
        try {
            if ($row['shipsDestroyed'] >= 100000 && $mt != 0) continue;
            if ($row["shipsDestroyed"] >= 100000 && $mt == 0) {
                if ($redis->set($highCountKey, "true", ['nx', 'ex' => 80000]) !== true) continue;
                $highCountKeySet = true;
            }

            if ($redis->set($key, "true", ['nx', 'ex' => 80000]) !== true) {
                continue;
            }
            //Util::out("calcTop $mt $i");

            calcTop($row, $i);
        } finally {
            if ($row["shipsDestroyed"] >= 100000 && $mt == 0 && $highCountKeySet) $redis->del($highCountKey);
            $redis->expire($key, 60); // helps prevent "race" condition 
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

    $inc = min(1001, ceil($currentSum * 0.01));
    $nextTopRecalc = $currentSum + $inc;

    $mdb->set('statistics', ['_id' => $row['_id']], ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc, 'calcAlltime' => false]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
