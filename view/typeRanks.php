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

    if ($page < 1) {
        return $response->withStatus(404);
    }

    $pageEpoch = $epoch;
    $entityType = "${type}ID";
    
    // Map epoch to period and determine solo suffix
    $period = $pageEpoch;
    $isSolo = ($solo == 'solo');
    $suffix = $isSolo ? '_solo' : '';
    
    if (!($solo == 'all' || $solo == 'solo')) {
        return $response->withStatus(404);
    }
    if (!($kl == 'k' || $kl == 'l')) {
        return $response->withStatus(404);
    }
    $subType = $kl == 'k' ? 'killers' : 'losers';

    $pageTitle = "Ranks for $type - $period$suffix - $subType - page $page";

    $names = ['character' => 'Characters', 'corporation' => 'Corporations', 'alliance' => 'Alliances', 'faction' => 'Factions', 'shipType' => 'Ships', 'group' => 'Groups'];
    $ranks = [];

    $start = ($page - 1) * $pageSize;
    
    // Query MongoDB for ranked entities
    $sortField = "ranks.{$period}{$suffix}.overall";
    $sortDirection = ($subType == 'killers') ? 1 : -1;
    
    $cursor = $mdb->getCollection('statistics')->find(
        [
            'type' => $entityType,
            $sortField => ['$exists' => true]
        ],
        [
            'sort' => [$sortField => $sortDirection],
            'skip' => $start,
            'limit' => $pageSize + 1,
            'projection' => [
                'id' => 1,
                "ranks.{$period}{$suffix}" => 1,
                "stats.{$period}{$suffix}" => 1
            ]
        ]
    );
    
    $result = [];
    $count = 0;
    foreach ($cursor as $doc) {
        if ($count >= $pageSize) break;
        $count++;
        
        $id = $doc['id'];
        $rankData = $doc['ranks'][$period . $suffix] ?? [];
        $statData = $doc['stats'][$period . $suffix] ?? [];

		$name = $mdb->findField('information', 'name', ['cacheTime' => 300, 'type' => $entityType == "shipTypeID" ? "typeID" : $entityType, 'id' => $id]);
        
        $row = [$entityType => $id];
		$nameField = $type == "shipType" ? "ship" : $type;
		$row["{$nameField}Name"] = $name;
        $row['overallRank'] = Util::rankCheck($rankData['overall'] ?? 0);
        
        $row['shipsDestroyed'] = $statData['shipsDestroyed'] ?? 0;
        $row['sdRank'] = Util::rankCheck($rankData['shipsDestroyed'] ?? 0);
        $row['shipsLost'] = $statData['shipsLost'] ?? 0;
        $row['slRank'] = Util::rankCheck($rankData['shipsLost'] ?? 0);
        if ($row['shipsDestroyed'] + $row['shipsLost'] == 0) $row['shipEff'] = 0;
        else $row['shipEff'] = ($row['shipsDestroyed'] / ($row['shipsDestroyed'] + $row['shipsLost'])) * 100;

        $row['iskDestroyed'] = $statData['iskDestroyed'] ?? 0;
        $row['idRank'] = Util::rankCheck($rankData['iskDestroyed'] ?? 0);
        $row['iskLost'] = $statData['iskLost'] ?? 0;
        $row['ilRank'] = Util::rankCheck($rankData['iskLost'] ?? 0);
        if ($row['shipEff'] == 0) $row['iskEff'] = 0;
        else $row['iskEff'] = ($row['iskDestroyed'] / ($row['iskDestroyed'] + $row['iskLost'])) * 100;

        $row['pointsDestroyed'] = $statData['pointsDestroyed'] ?? 0;
        $row['pdRank'] = Util::rankCheck($rankData['pointsDestroyed'] ?? 0);
        $row['pointsLost'] = $statData['pointsLost'] ?? 0;
        $row['plRank'] = Util::rankCheck($rankData['pointsLost'] ?? 0);
        if ($row['shipEff'] == 0) $row['pointsEff'] = 0;
        else $row['pointsEff'] = ($row['pointsDestroyed'] / ($row['pointsDestroyed'] + $row['pointsLost'])) * 100;

        $result[] = $row;
    }
    
    if (sizeof($result) == 0) {
        return $response->withStatus(404);
    }
    
    $hasMore = ($count > $pageSize) ? 'y' : 'n';
    $ranks[] = array('type' => $type, 'data' => $result, 'name' => $names[$type]);

    return $container->get('view')->render($response, 'typeRanks.html', ['ranks' => $ranks, 'pageTitle' => $pageTitle, 'type' => str_replace("ID", "", $entityType), 'epoch' => $period, 'subType' => substr($subType, 0, 1), 'solo' => $solo, 'page' => $page, 'hasMore' => $hasMore]);
}
