<?php

function handler($request, $response, $args, $container) {
    global $mdb;

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
        return $response->withStatus(404)->withHeader('Cache-Tag', 'www,error,404,ranks');
    }

    $pageEpoch = $epoch;
    $entityType = "${type}ID";

    if (!($solo == 'all' || $solo == 'solo')) {
        return $response->withStatus(404)->withHeader('Cache-Tag', "www,error,404,ranks,ranks:$type");
    }
    if (!($kl == 'k' || $kl == 'l')) {
        return $response->withStatus(404)->withHeader('Cache-Tag', "www,error,404,ranks,ranks:$type");
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

    $scope = $solo == 'solo' ? 'solo' : 'all';
    if (!Ranks::exists($pageEpoch, $scope, $entityType)) {
        return $response->withStatus(404)->withHeader('Cache-Tag', "www,error,404,ranks,ranks:$type");
    }

    if (!isset($validSorts[$sortKey])) {
        $sortKey = 'overallRank';
        $sortDir = $validSorts[$sortKey]['defaultDir'];
    }

    $pageTitle = "Ranks for $type - $pageEpoch - $subType - page $page";

    $names = ['character' => 'Characters', 'corporation' => 'Corporations', 'alliance' => 'Alliances', 'faction' => 'Factions', 'shipType' => 'Ships', 'group' => 'Groups'];
    $ranks = [];

    $column = $entityType;

    $pageDoc = Ranks::getPage($pageEpoch, $scope, $column, $sortKey, $sortDir, $page);
    if ($pageDoc == null || sizeof($pageDoc['ids'] ?? []) == 0) {
        return $response->withStatus(404)->withHeader('Cache-Tag', "www,error,404,ranks,ranks:$type");
    }

    $hasMore = $pageDoc['hasMore'] ?? 'n';
    $infoRows = loadRankPageInfo($column, $pageDoc['ids']);

    $result = [];
    foreach ($pageDoc['ids'] as $id) {
        $rankRow = $pageDoc['rows'][$id] ?? null;
        if ($rankRow == null) continue;

        $row = [$column => $id];
        if (isset($infoRows[$id])) $row = array_merge($row, $infoRows[$id]);
        $row['overallRank'] = (int) ($rankRow['ranks']['overall'] ?? 0);

        $row['shipsDestroyed'] = $rankRow['metrics']['shipsDestroyed'] ?? 0;
        $row['sdRank'] = $rankRow['ranks']['shipsDestroyed'] ?? '-';
        $row['shipsLost'] = $rankRow['metrics']['shipsLost'] ?? 0;
        $row['slRank'] = $rankRow['ranks']['shipsLost'] ?? '-';
        if ($row['shipsDestroyed'] + $row['shipsLost'] == 0) $row['shipEff'] = 0;
        else $row['shipEff'] = ($row['shipsDestroyed'] / ($row['shipsDestroyed'] + $row['shipsLost'])) * 100;

        $row['iskDestroyed'] = $rankRow['metrics']['iskDestroyed'] ?? 0;
        $row['idRank'] = $rankRow['ranks']['iskDestroyed'] ?? '-';
        $row['iskLost'] = $rankRow['metrics']['iskLost'] ?? 0;
        $row['ilRank'] = $rankRow['ranks']['iskLost'] ?? '-';
        if ($row['shipEff'] == 0) $row['iskEff'] = 0;
        else $row['iskEff'] = ($row['iskDestroyed'] / ($row['iskDestroyed'] + $row['iskLost'])) * 100;

        $row['pointsDestroyed'] = $rankRow['metrics']['pointsDestroyed'] ?? 0;
        $row['pdRank'] = $rankRow['ranks']['pointsDestroyed'] ?? '-';
        $row['pointsLost'] = $rankRow['metrics']['pointsLost'] ?? 0;
        $row['plRank'] = $rankRow['ranks']['pointsLost'] ?? '-';
        if ($row['shipEff'] == 0) $row['pointsEff'] = 0;
        else $row['pointsEff'] = ($row['pointsDestroyed'] / ($row['pointsDestroyed'] + $row['pointsLost'])) * 100;

        $result[] = $row;
    }
    $ranks[] = array('type' => $type, 'data' => $result, 'name' => $names[$type]);

    Info::addInfo($ranks);

    return $container->get('view')->render($response->withHeader('Cache-Tag', "www,ranks,ranks:$type,ranks:$type:$pageEpoch"), 'typeRanks.pug', ['ranks' => $ranks, 'pageTitle' => $pageTitle, 'type' => str_replace("ID", "", $column), 'epoch' => $pageEpoch, 'subType' => substr($subType, 0, 1), 'solo' => $solo, 'page' => $page, 'hasMore' => $hasMore, 'sortKey' => $sortKey, 'sortDir' => $sortDir]);
}

function loadRankPageInfo($column, $ids) {
    global $mdb;

    if (empty($ids)) return [];

    $infoType = $column == 'shipTypeID' ? 'typeID' : $column;
    $rows = $mdb->find(
        'information',
        ['type' => $infoType, 'id' => ['$in' => array_values($ids)]],
        [],
        null,
        ['id' => 1, 'name' => 1, 'ticker' => 1]
    );

    $result = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $name = $row['name'] ?? "$column $id";
        $result[$id] = ['id' => $id, 'name' => $name];

        switch ($column) {
            case 'characterID':
                $result[$id]['characterName'] = $name;
                break;
            case 'corporationID':
                $result[$id]['corporationName'] = $name;
                $result[$id]['cticker'] = $row['ticker'] ?? null;
                break;
            case 'allianceID':
                $result[$id]['allianceName'] = $name;
                $result[$id]['aticker'] = $row['ticker'] ?? null;
                break;
            case 'factionID':
                $result[$id]['factionName'] = $name;
                break;
            case 'shipTypeID':
                $result[$id]['shipName'] = $name;
                break;
            case 'groupID':
                $result[$id]['groupName'] = $name;
                break;
        }
    }

    return $result;
}
