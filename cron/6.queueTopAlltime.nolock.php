<?php

$mt = 5; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") > 1000) exit();
if ($redis->get("zkb:reinforced") == true) exit();
$queueStatsSet = new MongoQueue($mdb, 'queueStatsSet');
if ($queueStatsSet->count() > 1000) exit();

$minute = date("Hi");
while ($minute == date("Hi")) {
    $direction = $mt === 0 ? -1 : 1;
    $rows = $mdb->find("statistics", ['calcAlltime' => true, 'reset' => ['$ne' => true]], ['shipsDestroyed' => $direction], 25);
    if (sizeof($rows) == 0) exit();
    foreach ($rows as $row) {
        if ($row['id'] == 0) {
            $mdb->set("statistics", $row, ['calcAlltime' => false]);
            continue;
        }
        $cacheKey = "zkb:topAlltime:" . $row['type'] . ":" . $row['id'];
        $instanceKey = "x";
        $proceed = false;
        $set = false;
        $instance = -1;
        try {
            // Ensure we never have more than 5 instances running at once
            $instanceKey = "zkb:topAlltimeInstance:$mt:";
            if ($redis->set($instanceKey, "true", ['nx', 'ex' => 86400]) === true) {
                $proceed = true;
            } else {
                sleep(1);
                break; // 5 or more instances already running.....
            }
            if ($mt > 0 && $row['shipsDestroyed'] > 1000000) {
                // only $mt 0 should be handling very large stats to prevent excessive high laod
                // from multiple threads handling large stats
                sleep(1);
                continue;
            }

            $i = $row['type'] . " " . $row['id'] . " : " . $row['shipsDestroyed'];

            if ($redis->set($cacheKey, "false", ['nx', 'ex' => 86400]) !== true) continue;
            $set = true;

            //Util::out("$mt $i");
            //$now = time();
            calcTop($row);
            //Util::out("calcTop $i -> " . (time() - $now) . " seconds");
        } finally {
            if ($proceed) $redis->del($instanceKey);
            if ($set) $redis->del($cacheKey);
        }
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
    if ($row['type'] == 'label') $parameters['labels'] = $row['id'];
    else $parameters['labels'] = 'pvp';

    $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters, true, false));
    $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters, true, false));
    $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters, true, false));
    $topLists[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters, true, false));
    $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters, true, false));
    $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters, true, false));

    $p = $parameters;
    $p['limit'] = 6;
    $topKills = array_keys(Stats::getTopIsk($p));

    if ($row['type'] == 'groupID' || $row['type'] == 'label') $inc = ceil($currentSum * 0.01);
    else $inc = min(11111, ceil($currentSum * 0.01));
    $nextTopRecalc = $currentSum + $inc;

    $mdb->set('statistics', ['_id' => $row['_id']], ['topAllTime' => $topLists, 'topIskKills' => $topKills, 'allTimeSum' => $currentSum, 'nextTopRecalc' => $nextTopRecalc, 'calcAlltime' => false]);
    $mdb->removeField('statistics', $row, 'calcAlltime');
}
