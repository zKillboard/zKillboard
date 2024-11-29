<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

MongoCursor::$timeout = -1;

$today = date('Ymd');
$time = time();
$timeKey = $time - ($time % 1200);
$itimeKey = "zkb:weeklyRanksCalculated:$timeKey";
if ($redis->get($itimeKey) == true) {
    exit();
}

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$completed = [];

$everyone = [];

Util::out('weekly time ranks - first iteration');
$types = [];
$iter = $mdb->getCollection("oneWeek")->find();
foreach ($iter as $row) {
    $involved = $row['involved'];
    foreach ($involved as $entity) {
        foreach ($entity as $type => $id) {
            if (strpos($type, 'ID') === false) {
                continue;
            }

            if (isset($completed["$type:$id"])) continue;
            $completed["$type:$id"] = true;
            $everyone[] = ['type' => $type, 'id' => $id];

            $types[$type] = true;
            $key = "tq:ranks:weekly:$type:$today";

            $weeklyKills = getWeekly($type, $id, false);
            $weeklyLosses = getWeekly($type, $id, true);
            if ($weeklyKills + $weeklyLosses == 0) continue;

            $multi = $redis->multi();
            zAdd($multi, "$key:shipsDestroyed", $weeklyKills['killIDCount'], $id);
            zAdd($multi, "$key:pointsDestroyed", $weeklyKills['zkb_pointsSum'], $id);
            zAdd($multi, "$key:iskDestroyed", $weeklyKills['zkb_totalValueSum'], $id);
            zAdd($multi, "$key:shipsLost", $weeklyLosses['killIDCount'], $id);
            zAdd($multi, "$key:pointsLost", $weeklyLosses['zkb_pointsSum'], $id);
            zAdd($multi, "$key:iskLost", $weeklyLosses['zkb_totalValueSum'], $id);
            $multi->exec();
        }
    }
}
$completed = []; // clear the array and free memory

Util::out('weekly time ranks - second iteration');
foreach ($types as $type => $value) {
    $key = "tq:ranks:weekly:$type:$today";
    $indexKey = "$key:shipsDestroyed";
    $max = $redis->zCard($indexKey);
    $redis->del("tq:ranks:weekly:$type:$today");

    $it = null;
    while ($arr_matches = $redis->zScan($indexKey, $it)) {
        foreach ($arr_matches as $id => $score) {
            $redis->get('no timeout please'); // prevent redis timeouts

            $shipsDestroyed = $redis->zScore("$key:shipsDestroyed", $id);
            $shipsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:shipsDestroyed", $id));
            $shipsLost = $redis->zScore("$key:shipsLost", $id);
            $shipsLostRank = rankCheck($max, $redis->zRevRank("$key:shipsLost", $id));
            if ($shipsDestroyed + $shipsLost == 0) continue;
            $shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

            $iskDestroyed = $redis->zScore("$key:iskDestroyed", $id);
            $iskDestroyedRank = rankCheck($max, $redis->zRevRank("$key:iskDestroyed", $id));
            $iskLost = $redis->zScore("$key:iskLost", $id);
            $iskLostRank = rankCheck($max, $redis->zRevRank("$key:iskLost", $id));
            if (($iskDestroyed + $iskLost) == 0) continue;
            $iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

            $pointsDestroyed = $redis->zScore("$key:pointsDestroyed", $id);
            $pointsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id));
            $pointsLost = $redis->zScore("$key:pointsLost", $id);
            $pointsLostRank = rankCheck($max, $redis->zRevRank("$key:pointsLost", $id));
            if (($pointsDestroyed + $pointsLost) == 0) continue;
            $pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

            $avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
            $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
            $score = ceil($avg / $adjuster);

            $redis->zAdd("tq:ranks:weekly:$type:$today", $score, $id);
            $redis->expire("tq:ranks:weekly:$type:$today", 9000);
        }
    }
}

foreach ($types as $type => $value) {
    $multi = $redis->multi();
    $multi->del("tq:ranks:weekly:$type");
    $multi->zUnionStore("tq:ranks:weekly:$type", ["tq:ranks:weekly:$type:$today"]);
    $multi->expire("tq:ranks:weekly:$type", 9000);
    $multi->expire("tq:ranks:weekly:$type:$today", (7 * 86400));
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:shipsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:shipsLost");
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:iskDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:iskLost");
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:pointsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:weekly:$type:$today:pointsLost");
    $multi->exec();
}

foreach ($everyone as $i => $next) {
break;
    if ($redis->get("zkb:overview:$type:$id") === "true") Util::statsBoxUpdate($next['type'], $next['id']);
}

$redis->setex($itimeKey, 900, true);
Util::out('Weekly rankings complete');

function moveAndExpire(&$multi, $today, $key)
{
    $newKey = str_replace(":$today", '', $key);
    $multi->rename($key, $newKey);
    $multi->expire($newKey, 9000);
}

function zAdd(&$multi, $key, $value, $id)
{
    $value = max(0, (int) $value);
    $multi->zAdd($key, $value, $id);
    $multi->expire($key, 9000);
}

function rankCheck($max, $rank)
{
    return $rank === false ? $max : ($rank + 1);
}

function getWeekly($type, $id, $isVictim)
{
    global $mdb;

    // build the query
    $query = [$type => $id, 'isVictim' => $isVictim, 'npc' => false, 'categoryID' => 6];
    $query = MongoFilter::buildQuery($query);

    $result = $mdb->group('oneWeek', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
    return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}
