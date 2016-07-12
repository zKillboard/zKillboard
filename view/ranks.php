<?php

global $redis;

if (!in_array($pageType, array('recent', 'alltime', 'weekly'))) $app->notFound();

if (!in_array($subType, array('killers', 'losers'))) $app->notFound();

$pageTitle = $pageType == 'recent' ? 'Ranks - Recent (Past 90 Days)' : 'Alltime Ranks';
$tableTitle = $pageType == 'recent' ? 'Recent Rank' : 'Alltime Rank';

$types = array('pilot' => 'characterID', 'corp' => 'corporationID', 'alli' => 'allianceID', 'faction' => 'factionID');
$names = array('character' => 'Characters', 'corp' => 'Corporations', 'alli' => 'Alliances', 'faction' => 'Factions');
$ranks = array();
foreach ($types as $type => $column) {
    if ($subType == 'killers') $r = $redis->zRange("tq:ranks:$pageType:$column", 0, 9);
    else $r = $redis->zRevRange("tq:ranks:$pageType:$column", 0, 9);
    $result = [];
    foreach ($r as $row) {
        $id = $row;
        $row = [$column => $row];
        $row['overallRank'] = Util::rankCheck($redis->zRank("tq:ranks:$pageType:$column", $id));

        $row['shipsDestroyed'] = $redis->zScore("tq:ranks:$pageType:$column:shipsDestroyed", $id);
        $row['sdRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:shipsDestroyed", $id));
        $row['shipsLost'] = $redis->zScore("tq:ranks:$pageType:$column:shipsLost", $id);
        $row['slRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:shipsLost", $id));
        $row['shipEff'] = ($row['shipsDestroyed'] / ($row['shipsDestroyed'] + $row['shipsLost'])) * 100;

        $row['iskDestroyed'] = $redis->zScore("tq:ranks:$pageType:$column:iskDestroyed", $id);
        $row['idRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:iskDestroyed", $id));
        $row['iskLost'] = $redis->zScore("tq:ranks:$pageType:$column:iskLost", $id);
        $row['ilRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:iskLost", $id));
        $row['iskEff'] = ($row['iskDestroyed'] / ($row['iskDestroyed'] + $row['iskLost'])) * 100;

        $row['pointsDestroyed'] = $redis->zScore("tq:ranks:$pageType:$column:pointsDestroyed", $id);
        $row['pdRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:pointsDestroyed", $id));
        $row['pointsLost'] = $redis->zScore("tq:ranks:$pageType:$column:pointsLost", $id);
        $row['plRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:$pageType:$column:pointsLost", $id));
        $row['pointsEff'] = ($row['pointsDestroyed'] / ($row['pointsDestroyed'] + $row['pointsLost'])) * 100;

        $result[] = $row;

    }
    if ($type == 'pilot') {
        $type = 'character';
    }
    $ranks[] = array('type' => $type, 'data' => $result, 'name' => $names[$type]);
}

Info::addInfo($ranks);

$app->render('ranks.html', array('ranks' => $ranks, 'pageTitle' => $pageTitle, 'tableTitle' => $tableTitle, 'pageType' => $pageType, 'subType' => $subType));
