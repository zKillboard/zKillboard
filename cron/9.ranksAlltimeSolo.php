<?php

require_once '../init.php';


$today = date('Ymd', time() - (3600 * 4));

$types = [        "allianceID",
        "characterID",
        "constellationID",
        "corporationID",
        "factionID",
        "groupID",
        "locationID",
        "regionID",
        "solarSystemID", "shipTypeID"];

$types[] = 'exit';
$type = null;
$todaysKey = null;
foreach ($types as $type) {
    $todaysKey = "zkb:alltimeSoloRanksCalculated:$type:$today";
    if ($redis->get($todaysKey) == true) continue;
    break;
}
if ($type == 'exit') exit();
Util::out("Calculating solo alltime ranks for $type");
$types = [];


$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['DestroyedSolo', 'LostSolo'];

$information = $mdb->getCollection('statistics');

Util::out("Solo Alltime ranks - first iteration $type");
$iter = $information->find(['type' => $type]);

foreach ($iter as $row) {
    $id = $row['id'];

    if (@$row['shipsDestroyedSolo'] < 100) continue;

    $types[$type] = true;
    $key = "tq:ranks:alltime:solo:$type:$today";

    $multi = $redis->multi();
    zAdd($multi, "$key:shipsDestroyedSolo", @$row['shipsDestroyedSolo'], $id);
    zAdd($multi, "$key:shipsLostSolo", @$row['shipsLostSolo'], $id);
    zAdd($multi, "$key:pointsDestroyedSolo", @$row['pointsDestroyedSolo'], $id);
    zAdd($multi, "$key:pointsLostSolo", @$row['pointsLostSolo'], $id);
    zAdd($multi, "$key:iskDestroyedSolo", @$row['iskDestroyedSolo'], $id);
    zAdd($multi, "$key:iskLostSolo", @$row['iskLostSolo'], $id);
    $multi->exec();
}

Util::out("Solo Alltime ranks - second iteration $type");
foreach ($types as $type => $value) {
    $key = "tq:ranks:alltime:solo:$type:$today";
    $indexKey = "$key:shipsDestroyedSolo";
    $max = $redis->zCard($indexKey);
    $redis->del("tq:ranks:alltime:solo:$type:$today");

    $it = null;
    while ($arr_matches = $redis->zScan($indexKey, $it)) {
        foreach ($arr_matches as $id => $score) {
            $shipsDestroyedSolo = $redis->zScore("$key:shipsDestroyedSolo", $id);
            $shipsDestroyedSoloRank = rankCheck($max, $redis->zRevRank("$key:shipsDestroyedSolo", $id));
            $shipsLostSolo = $redis->zScore("$key:shipsLostSolo", $id);
            $shipsLostSoloRank = rankCheck($max, $redis->zRevRank("$key:shipsLostSolo", $id));
            $shipsEff = ($shipsDestroyedSolo / ($shipsDestroyedSolo + $shipsLostSolo));

            $iskDestroyedSolo = $redis->zScore("$key:iskDestroyedSolo", $id);
            if ($iskDestroyedSolo == 0) {
                continue;
            }
            $iskDestroyedSoloRank = rankCheck($max, $redis->zRevRank("$key:iskDestroyedSolo", $id));
            $iskLostSolo = $redis->zScore("$key:iskLostSolo", $id);
            $iskLostSoloRank = rankCheck($max, $redis->zRevRank("$key:iskLostSolo", $id));
            $iskEff = ($iskDestroyedSolo / ($iskDestroyedSolo + $iskLostSolo));

            $pointsDestroyedSolo = $redis->zScore("$key:pointsDestroyedSolo", $id);
            $pointsDestroyedSoloRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyedSolo", $id));
            $pointsLostSolo = $redis->zScore("$key:pointsLostSolo", $id);
            $pointsLostSoloRank = rankCheck($max, $redis->zRevRank("$key:pointsLostSolo", $id));
            $pointsEff = ($pointsDestroyedSolo / ($pointsDestroyedSolo + $pointsLostSolo));

            $avg = ceil(($shipsDestroyedSoloRank + $iskDestroyedSoloRank + $pointsDestroyedSoloRank) / 3);
            $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
            $score = ceil($avg / $adjuster);

            $redis->zAdd("tq:ranks:alltime:solo:$type:$today", $score, $id);
        }
    }
}

foreach ($types as $type => $value) {
    $multi = $redis->multi();
    $multi->zUnionStore("tq:ranks:alltime:solo:$type", ["tq:ranks:alltime:solo:$type:$today"]);
    $multi->expire("tq:ranks:alltime:solo:$type:$today", (7 * 86400));
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:shipsDestroyedSolo");
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:shipsLostSolo");
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:iskDestroyedSolo");
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:iskLostSolo");
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:pointsDestroyedSolo");
    moveAndExpire($multi, $today, "tq:ranks:alltime:solo:$type:$today:pointsLostSolo");
    $multi->exec();
}

$redis->setex($todaysKey, 87000, true);
Util::out("Alltime rankings complete $type");

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
