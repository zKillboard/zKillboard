<?php

require_once '../init.php';

$today = date('Ymd');
$todaysKey = "RC:recentRanksCalculated:$today";
if ($redis->get($todaysKey) == true) {
    exit();
}

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$now = time();
$now = $now - ($now % 60);
$then = $now - (90 * 86400);
$ninetyDayKillID = $mdb->findField('killmails', 'killID', ['dttm' => ['$gte' => new MongoDate($then)]], ['killID' => 1]);
if ($ninetyDayKillID == null) {
    $redis->setex($todaysKey, 87000, true);
    exit();
}

$information = $mdb->getCollection('statistics');

Util::out('recent time ranks - first iteration');
$types = [];
$iter = $information->find();
$iter->timeout(0);
foreach ($iter as $row) {
    $type = $row['type'];
    $id = $row['id'];

    $killID = getLatestKillID($type, $id, $ninetyDayKillID);
    if ($killID < $ninetyDayKillID) {
        continue;
    }

    $types[$type] = true;
    $key = "tq:ranks:recent:$type:$today";

    $recentKills = getRecent($row['type'], $row['id'], false, $ninetyDayKillID);
    $recentLosses = getRecent($row['type'], $row['id'], true, $ninetyDayKillID);

    $multi = $redis->multi();
    zAdd($multi, "$key:shipsDestroyed", $recentKills['killIDCount'], $id);
    zAdd($multi, "$key:pointsDestroyed", $recentKills['zkb_pointsSum'], $id);
    zAdd($multi, "$key:iskDestroyed", $recentKills['zkb_totalValueSum'], $id);
    zAdd($multi, "$key:shipsLost", $recentLosses['killIDCount'], $id);
    zAdd($multi, "$key:pointsLost", $recentLosses['zkb_pointsSum'], $id);
    zAdd($multi, "$key:iskLost", $recentLosses['zkb_totalValueSum'], $id);
    $multi->exec();
}

Util::out('recent time ranks - second iteration');
foreach ($types as $type => $value) {
    $key = "tq:ranks:recent:$type:$today";
    $indexKey = "$key:shipsDestroyed";
    $max = $redis->zCard($indexKey);
    $redis->del("tq:ranks:recent:$type:$today");

    $it = null;
    while ($arr_matches = $redis->zScan($indexKey, $it)) {
        foreach ($arr_matches as $id => $score) {
            $shipsDestroyed = $redis->zScore("$key:shipsDestroyed", $id);
            $shipsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:shipsDestroyed", $id));
            $shipsLost = $redis->zScore("$key:shipsLost", $id);
            $shipsLostRank = rankCheck($max, $redis->zRevRank("$key:shipsLost", $id));
            $shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

            $iskDestroyed = $redis->zScore("$key:iskDestroyed", $id);
            if ($iskDestroyed == 0) {
                continue;
            }
            $iskDestroyedRank = rankCheck($max, $redis->zRevRank("$key:iskDestroyed", $id));
            $iskLost = $redis->zScore("$key:iskLost", $id);
            $iskLostRank = rankCheck($max, $redis->zRevRank("$key:iskLost", $id));
            $iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

            $pointsDestroyed = $redis->zScore("$key:pointsDestroyed", $id);
            $pointsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id));
            $pointsLost = $redis->zScore("$key:pointsLost", $id);
            $pointsLostRank = rankCheck($max, $redis->zRevRank("$key:pointsLost", $id));
            $pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

            $avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
            $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
            $score = ceil($avg / $adjuster);

            $redis->zAdd("tq:ranks:recent:$type:$today", $score, $id);
        }
    }
}

foreach ($types as $type => $value) {
    $multi = $redis->multi();
    $multi->del("tq:ranks:recent:$type");
    $multi->zUnion("tq:ranks:recent:$type", ["tq:ranks:recent:$type:$today"]);
    $multi->expire("tq:ranks:recent:$type", 100000);
    $multi->expire("tq:ranks:recent:$type:$today", (7 * 86400));
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:shipsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:shipsLost");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:iskDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:iskLost");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:pointsDestroyed");
    moveAndExpire($multi, $today, "tq:ranks:recent:$type:$today:pointsLost");
    $multi->exec();
}

$redis->setex($todaysKey, 87000, true);
Util::out('Recent rankings complete');

function zAdd(&$multi, $key, $value, $id)
{
    $value = max(1, (int) $value);
    $multi->zAdd($key, $value, $id);
    $multi->expire($key, 100000);
}

function moveAndExpire(&$multi, $today, $key)
{
    $newKey = str_replace(":$today", '', $key);
    $multi->rename($key, $newKey);
    $multi->expire($newKey, 100000);
}

function rankCheck($max, $rank)
{
    return $rank === false ? $max : ($rank + 1);
}

function getRecent($type, $id, $isVictim, $ninetyDayKillID)
{
    global $mdb;

    // build the query
    $query = [$type => $id, 'isVictim' => $isVictim];
    $query = MongoFilter::buildQuery($query);
    // set the proper sequence values
    $query = ['$and' => [['killID' => ['$gte' => $ninetyDayKillID]], $query]];

    $result = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);

    return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}

function getLatestKillID($type, $id, $ninetyDayKillID)
{
    global $mdb;

    // build the query
    $query = [$type => $id, 'isVictim' => false];
    $query = MongoFilter::buildQuery($query);
    // set the proper sequence values
    $query = ['$and' => [['killID' => ['$gte' => $ninetyDayKillID]], $query]];

    $killmail = $mdb->findDoc('killmails', $query);
    if ($killmail == null) {
        return 0;
    }

    return $killmail['killID'];
}
