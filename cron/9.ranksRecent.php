<?php

require_once '../init.php';

MongoCursor::$timeout = -1;

$today = date('Ymd');
$hourKey = "zkb:recentRanksCalculated:"  . $today;
if ($redis->get($hourKey) == true) {
    exit();
}

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$completed = [];

Util::out('recent time ranks - first iteration');
$types = [];
$iter = $mdb->getCollection("ninetyDays")->find();
foreach ($iter as $row) {
    $involved = $row['involved'];
    foreach ($involved as $entity) {
        foreach ($entity as $type => $id) {
            if (strpos($type, 'ID') === false) {
                continue;
            }

            if (isset($completed["$type:$id"])) continue;
            $completed["$type:$id"] = true;

            $types[$type] = true;
            $key = "tq:ranks:recent:$type:$today";

            $recentKills = getRecent($type, $id, false);
            $recentLosses = getRecent($type, $id, true);
            if ($recentKills + $recentLosses == 0) continue;

            $multi = $redis->multi();
            zAdd($multi, "$key:shipsDestroyed", $recentKills['killIDCount'], $id);
            zAdd($multi, "$key:pointsDestroyed", $recentKills['zkb_pointsSum'], $id);
            zAdd($multi, "$key:iskDestroyed", $recentKills['zkb_totalValueSum'], $id);
            zAdd($multi, "$key:shipsLost", $recentLosses['killIDCount'], $id);
            zAdd($multi, "$key:pointsLost", $recentLosses['zkb_pointsSum'], $id);
            zAdd($multi, "$key:iskLost", $recentLosses['zkb_totalValueSum'], $id);
            $multi->exec();
        }
    }
}
$completed = []; // clear the array and free memory

Util::out('recent time ranks - second iteration');
foreach ($types as $type => $value) {
    $key = "tq:ranks:recent:$type:$today";
    $indexKey = "$key:shipsDestroyed";
    $max = $redis->zCard($indexKey);
    $redis->del("tq:ranks:recent:$type:$today");

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
            $iskEff = ($iskDestroyed + $iskLost) == 0 ? 0 : ($iskDestroyed / ($iskDestroyed + $iskLost));

            $pointsDestroyed = $redis->zScore("$key:pointsDestroyed", $id);
            $pointsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id));
            $pointsLost = $redis->zScore("$key:pointsLost", $id);
            $pointsLostRank = rankCheck($max, $redis->zRevRank("$key:pointsLost", $id));
            $pointsEff = ($pointsDestroyed + $pointsLost) == 0 ? 0 : ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

            $avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
            $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
            $score = ceil($avg / $adjuster);

            $redis->zAdd("tq:ranks:recent:$type:$today", $score, $id);
            $redis->expire("tq:ranks:recent:$type:$today", 86400);
        }
    }
}

foreach ($types as $type => $value) {
    $multi = $redis->multi();
    $multi->del("tq:ranks:recent:$type");
    $multi->zUnionStore("tq:ranks:recent:$type", ["tq:ranks:recent:$type:$today"]);
    $multi->expire("tq:ranks:recent:$type", 86400);
    $multi->expire("tq:ranks:recent:$type:$today", (7 * 86400));
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:shipsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:shipsLost");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:iskDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:iskLost");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:pointsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:pointsLost");
    $multi->exec();
}

function moveAndExpire(&$multi, $today, $key)
{
    $newKey = str_replace(":$today", '', $key);
    $multi->rename($key, $newKey);
    $multi->expire($newKey, 86400);
}

$redis->setex($hourKey, 86400, true);
Util::out('Recent rankings complete');

function zAdd(&$multi, $key, $value, $id)
{
    $value = max(0, (int) $value);
    $multi->zAdd($key, $value, $id);
    $multi->expire($key, 86400);
}

function rankCheck($max, $rank)
{
    return $rank === false ? $max : ($rank + 1);
}

function getRecent($type, $id, $isVictim)
{
    global $mdb;

    // build the query
    $query = [$type => $id, 'isVictim' => $isVictim];
    if ($isVictim == true) $query['npc'] = false;
    $query = MongoFilter::buildQuery($query);

    $result = $mdb->group('ninetyDays', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
    return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}
