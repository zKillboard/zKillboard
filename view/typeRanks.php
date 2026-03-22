<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    $pageSize = 500;
    
    // Extract parameters from args
    $type = $args['type'];
    $kl = $args['kl'];
    $solo = $args['solo'];
    $epoch = $args['epoch'];
    $page = (int) $args['page'];
    $sortKey = @$args['sort'];
    $sortDir = strtolower(@$args['dir']) == 'asc' ? 'asc' : 'desc';

    if ($page < 1) {
        return $response->withStatus(404);
    }

    $pageEpoch = $epoch;
    $entityType = "${type}ID";
    if (!$redis->exists("tq:ranks:$pageEpoch:$entityType")) {
        return $response->withStatus(404);
    }

    if (!($solo == 'all' || $solo == 'solo')) {
        return $response->withStatus(404);
    }
    if (!($kl == 'k' || $kl == 'l')) {
        return $response->withStatus(404);
    }
    $subType = $kl == 'k' ? 'killers' : 'losers';

    $validSorts = [
        'overallRank' => ['redisKey' => '', 'ascMethod' => 'range', 'defaultDir' => ($subType == 'killers' ? 'asc' : 'desc')],
        'shipsDestroyed' => ['redisKey' => 'shipsDestroyed', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'sdRank' => ['redisKey' => 'shipsDestroyed', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
        'shipsLost' => ['redisKey' => 'shipsLost', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'slRank' => ['redisKey' => 'shipsLost', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
        'pointsDestroyed' => ['redisKey' => 'pointsDestroyed', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'pdRank' => ['redisKey' => 'pointsDestroyed', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
        'pointsLost' => ['redisKey' => 'pointsLost', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'plRank' => ['redisKey' => 'pointsLost', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
        'iskDestroyed' => ['redisKey' => 'iskDestroyed', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'idRank' => ['redisKey' => 'iskDestroyed', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
        'iskLost' => ['redisKey' => 'iskLost', 'ascMethod' => 'range', 'defaultDir' => 'desc'],
        'ilRank' => ['redisKey' => 'iskLost', 'ascMethod' => 'revrange', 'defaultDir' => 'asc'],
    ];

    if (!isset($validSorts[$sortKey])) {
        $sortKey = 'overallRank';
        $sortDir = $validSorts[$sortKey]['defaultDir'];
    }

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

    $sortInfo = $validSorts[$sortKey];
    $sortRedisKey = $sortInfo['redisKey'] == '' ? "tq:ranks:$pageEpoch:$column" : "tq:ranks:$pageEpoch:$column:{$sortInfo['redisKey']}$s";

    if (!$redis->exists($sortRedisKey)) {
        return $response->withStatus(404);
    }

    $useRevRange = ($sortDir == 'asc' && $sortInfo['ascMethod'] == 'revrange') || ($sortDir == 'desc' && $sortInfo['ascMethod'] == 'range');

    if ($useRevRange) {
        $r = $redis->zRevRange($sortRedisKey, $start, $end);
        $r2 = $redis->zRevRange($sortRedisKey, $start, $end + 1);
    } else {
        $r = $redis->zRange($sortRedisKey, $start, $end);
        $r2 = $redis->zRange($sortRedisKey, $start, $end + 1);
    }
    if (sizeof($r) == 0) {
        return $response->withStatus(404);
    }
    $hasMore = (sizeof($r2) > sizeof($r)) ? 'y' : 'n';

    $result = [];
    foreach ($r as $row) {
        $id = $row;
        $row = [$column => $row];
        $row['overallRank'] = (int) Util::rankCheck($redis->zRank("tq:ranks:$pageEpoch:$column", $id));

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

    return $container->get('view')->render($response, 'typeRanks.html', ['ranks' => $ranks, 'pageTitle' => $pageTitle, 'type' => str_replace("ID", "", $column), 'epoch' => $pageEpoch, 'subType' => substr($subType, 0, 1), 'solo' => $solo, 'page' => $page, 'hasMore' => $hasMore, 'sortKey' => $sortKey, 'sortDir' => $sortDir]);
}
