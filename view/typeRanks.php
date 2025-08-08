<?php

global $mdb, $redis;

$pageSize = 500;

$page = (int) $page;
if ($page < 1) return $app->notFound();

$pageEpoch = $epoch;
$entityType = "${type}ID";
if (!$redis->exists("tq:ranks:$pageEpoch:$entityType")) return $app->notFound();

if (!($solo == 'all' || $solo == 'solo')) return $app->notFound('solo not well defined');
if (!($kl == 'k' || $kl == 'l')) return $app->notFound("kl not well defined");
$subType = $kl == 'k' ? 'killers' : 'losers';

$s = "";
if ($solo == 'solo') {
    if ($pageEpoch == 'alltime') $s = "Solo";
    $pageEpoch .= ":solo"; 
}

$pageTitle = "Ranks for $type - $pageEpoch - $subType - page $page";

$names = ['character' => 'Characters', 'corporation' => 'Corporations', 'alliance' => 'Alliances', 'faction' => 'Factions', 'shipType' => 'Ships', 'group' => 'Groups'];
$ranks = [];

$column = $entityType;
$start = ($page - 1) * $pageSize;
$end = ($page * $pageSize) - 1;

if ($subType == 'killers') {
    $r = $redis->zRange("tq:ranks:$pageEpoch:$column", $start, $end);
    $r2 = $redis->zRange("tq:ranks:$pageEpoch:$column", $start, $end + 1);
} else {
    $r = $redis->zRevRange("tq:ranks:$pageEpoch:$column", $start, $end);
    $r2 = $redis->zRevRange("tq:ranks:$pageEpoch:$column", $start, $end + 1);
}
if (sizeof($r) == 0) return $app->notFound();
$hasMore = (sizeof($r2) > sizeof($r)) ? 'y' : 'n';

$result = [];
foreach ($r as $row) {
    $id = $row;
    $row = [$column => $row];
    $row['overallRank'] = Util::rankCheck($redis->zRank("tq:ranks:$pageEpoch:$column", $id));

    $row['shipsDestroyed'] = $redis->zScore("tq:ranks:$pageEpoch:$column:shipsDestroyed$s", $id);
    $row['sdRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:shipsDestroyed$s", $id));
    $row['shipsLost'] = $redis->zScore("tq:ranks:$pageEpoch:$column:shipsLost$s", $id);
    $row['slRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:shipsLost$s", $id));
    if ($row['shipsDestroyed'] + $row['shipsLost'] == 0) $row['shipEff'] = 0;
    else $row['shipEff'] = ($row['shipsDestroyed'] / ($row['shipsDestroyed'] + $row['shipsLost'])) * 100;

    $row['iskDestroyed'] = $redis->zScore("tq:ranks:$pageEpoch:$column:iskDestroyed$s", $id);
    $row['idRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:iskDestroyed$s", $id));
    $row['iskLost'] = $redis->zScore("tq:ranks:$pageEpoch:$column:iskLost$s", $id);
    $row['ilRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:iskLost$s", $id));
    if ($row['shipEff'] == 0) $row['iskEff'] = 0;
    else $row['iskEff'] = ($row['iskDestroyed'] / ($row['iskDestroyed'] + $row['iskLost'])) * 100;

    $row['pointsDestroyed'] = $redis->zScore("tq:ranks:$pageEpoch:$column:pointsDestroyed$s", $id);
    $row['pdRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:pointsDestroyed$s", $id));
    $row['pointsLost'] = $redis->zScore("tq:ranks:$pageEpoch:$column:pointsLost$s", $id);
    $row['plRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageEpoch:$column:pointsLost$s", $id));
    if ($row['shipEff'] == 0) $row['pointsEff'] = 0;
    else $row['pointsEff'] = ($row['pointsDestroyed'] / ($row['pointsDestroyed'] + $row['pointsLost'])) * 100;

    $result[] = $row;
}
$ranks[] = array('type' => $type, 'data' => $result, 'name' => $names[$type]);

Info::addInfo($ranks);

$pageEpoch = str_replace(":solo", "", $pageEpoch);

$app->render('typeRanks.html', ['ranks' => $ranks, 'pageTitle' => $pageTitle, 'type' => str_replace("ID", "", $column), 'epoch' => $pageEpoch, 'subType' => substr($subType, 0, 1), 'solo' => $solo, 'page' => $page, 'hasMore' => $hasMore]);
