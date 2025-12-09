<?php

function recap2025Handler($request, $response, $args, $container) {
    global $mdb, $redis;

    // Extract parameters (comes from overview.php)
    $inputString = $args['input'] ?? '';
    $input = explode('/', trim($inputString, '/'));
    
    $key = $input[0]; // character, corporation, or alliance
    $id = (int) $input[1];
    $type = $key; // For compatibility
    
    if (!in_array($key, ['character', 'corporation', 'alliance']) || $id == 0) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    // Check cache first (72 hour TTL)
    $cacheKey = "recap2025:$type:$id";
    $cached = $mdb->findDoc('keyvalues', ['key' => $cacheKey, 'expiresAt' => ['$gt' => $mdb->now()]]);
    if ($cached && isset($cached['value'])) {
		try {
        	$data = json_decode($cached['value'], true);
        	// Add generation time from the updated field
        	if (isset($cached['updated'])) {
        		$data['generationTime'] = $cached['updated'];
        	}
        	return $container->get('view')->render($response, 'recap2025.html', $data);
		} catch (Exception $e) {
			// Fall through to regenerate cache
		}
    }

    // Get entity info
    $typeField = $key . 'ID';
    $info = Info::getInfoDetails($typeField, $id);
    if ($info === null) {
        return $container->get('view')->render($response->withStatus(404), '404.html', array('message' => 'Not Found'));
    }

    // Get statistics from the statistics collection instead of querying all killmails
    $statistics = $mdb->findDoc('statistics', ['type' => $typeField, 'id' => $id]);
    
    if (!$statistics) {
        return $container->get('view')->render($response, 'recap2025.html', [
            'type' => $type,
            'id' => $id,
            'info' => $info,
            'year' => 2025,
            'totalKills' => 0,
            'totalLosses' => 0,
            'totalIskDestroyed' => 0,
            'totalIskLost' => 0,
            'totalPointsDestroyed' => 0,
            'totalPointsLost' => 0,
            'soloKills' => 0,
            'monthlyKills' => [],
            'monthlyLosses' => [],
            'topVictimCharacters' => [],
            'topVictimCorporations' => [],
            'topVictimAlliances' => [],
            'topKillerCharacters' => [],
            'topKillerCorporations' => [],
            'topKillerAlliances' => [],
            'topShipsUsed' => [],
            'topShipsLost' => [],
            'topSystems' => [],
            'topRegions' => [],
            'efficiency' => 0,
            'iskEfficiency' => 0,
            'info_all' => []
        ]);
    }
    
    // Extract 2025 monthly data
    $monthlyKills = [];
    $monthlyLosses = [];
    $totalKills = 0;
    $totalLosses = 0;
    $totalIskDestroyed = 0;
    $totalIskLost = 0;
    $totalPointsDestroyed = 0;
    $totalPointsLost = 0;
    
    if (isset($statistics['months'])) {
        foreach ($statistics['months'] as $monthKey => $monthData) {
            if ($monthData['year'] == 2025) {
                $monthStr = sprintf('%04d-%02d', $monthData['year'], $monthData['month']);
                
                $kills = (int) (@$monthData['shipsDestroyed'] ?? 0);
                $losses = (int) (@$monthData['shipsLost'] ?? 0);
                
                if ($kills > 0) {
                    $monthlyKills[$monthStr] = $kills;
                    $totalKills += $kills;
                    $totalIskDestroyed += (float) (@$monthData['iskDestroyed'] ?? 0);
                    $totalPointsDestroyed += (float) (@$monthData['pointsDestroyed'] ?? 0);
                }
                
                if ($losses > 0) {
                    $monthlyLosses[$monthStr] = $losses;
                    $totalLosses += $losses;
                    $totalIskLost += (float) (@$monthData['iskLost'] ?? 0);
                    $totalPointsLost += (float) (@$monthData['pointsLost'] ?? 0);
                }
            }
        }
    }
    
    // Solo kills approximation (not in monthly stats, use overall)
    $soloKills = (int) (@$statistics['shipsDestroyedSolo'] ?? 0);
    
    // Use aggregation to get top victims/killers/ships/systems without loading all documents
    $collection = $mdb->getCollection('killmails');
    
    // Get biggest kill/loss from killmails collection
    $startTime = new \MongoDB\BSON\UTCDateTime(strtotime('2025-01-01 00:00:00') * 1000);
    $endTime = new \MongoDB\BSON\UTCDateTime(strtotime('2025-12-31 23:59:59') * 1000);
    
    // For kills, we need to ensure the entity is NOT the victim (victim is at index 0)
    $killsQuery = [
        'involved.' . $typeField => $id,
        'involved.0.' . $typeField => ['$ne' => $id],
        'dttm' => ['$gte' => $startTime, '$lte' => $endTime],
        'labels' => 'cat:6'
    ];
    $biggestKill = $mdb->findDoc('killmails', $killsQuery, ['zkb.totalValue' => -1]);
    $biggestKillValue = (float) (@$biggestKill['zkb']['totalValue'] ?? 0);
    
    $lossesQuery = [
        'involved.0.' . $typeField => $id,
        'involved.0.isVictim' => true,
        'dttm' => ['$gte' => $startTime, '$lte' => $endTime],
        'labels' => 'cat:6'
    ];
    $biggestLoss = $mdb->findDoc('killmails', $lossesQuery, ['zkb.totalValue' => -1]);
    $biggestLossValue = (float) (@$biggestLoss['zkb']['totalValue'] ?? 0);
    
    // Get all top victims in one aggregation using $facet
    $pipeline = [
        ['$match' => $killsQuery],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.isVictim' => true]],
        ['$facet' => [
            'characters' => [
                ['$group' => ['_id' => '$involved.characterID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'corporations' => [
                ['$group' => ['_id' => '$involved.corporationID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'alliances' => [
                ['$match' => ['involved.allianceID' => ['$gt' => 0]]],
                ['$group' => ['_id' => '$involved.allianceID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ]
        ]]
    ];
    
    $victimResults = $collection->aggregate($pipeline)->toArray()[0];
    
    $topVictimCharacters = [];
    foreach ($victimResults['characters'] as $result) {
        if ($result['_id']) $topVictimCharacters[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topVictimCorporations = [];
    foreach ($victimResults['corporations'] as $result) {
        if ($result['_id']) $topVictimCorporations[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topVictimAlliances = [];
    foreach ($victimResults['alliances'] as $result) {
        if ($result['_id']) $topVictimAlliances[(int)$result['_id']] = (int)$result['count'];
    }
    
    // Get all top killers in one aggregation using $facet
    $pipeline = [
        ['$match' => $lossesQuery],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.isVictim' => false]],
        ['$facet' => [
            'characters' => [
                ['$group' => ['_id' => '$involved.characterID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'corporations' => [
                ['$group' => ['_id' => '$involved.corporationID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'alliances' => [
                ['$match' => ['involved.allianceID' => ['$gt' => 0]]],
                ['$group' => ['_id' => '$involved.allianceID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ]
        ]]
    ];
    
    $killerResults = $collection->aggregate($pipeline)->toArray()[0];
    
    $topKillerCharacters = [];
    foreach ($killerResults['characters'] as $result) {
        if ($result['_id']) $topKillerCharacters[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topKillerCorporations = [];
    foreach ($killerResults['corporations'] as $result) {
        if ($result['_id']) $topKillerCorporations[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topKillerAlliances = [];
    foreach ($killerResults['alliances'] as $result) {
        if ($result['_id']) $topKillerAlliances[(int)$result['_id']] = (int)$result['count'];
    }
    
    // Get top ships used and systems/regions in one query using $facet
    $pipeline = [
        ['$match' => $killsQuery],
        ['$facet' => [
            'shipsUsed' => [
                ['$unwind' => '$involved'],
                ['$match' => [
                    'involved.' . $typeField => $id,
                    'involved.isVictim' => false
                ]],
                ['$group' => ['_id' => '$involved.shipTypeID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'systems' => [
                ['$group' => ['_id' => '$system.solarSystemID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ],
            'regions' => [
                ['$group' => ['_id' => '$system.regionID', 'count' => ['$sum' => 1]]],
                ['$sort' => ['count' => -1]],
                ['$limit' => 5]
            ]
        ]]
    ];
    
    $killsResults = $collection->aggregate($pipeline)->toArray()[0];
    
    $topShipsUsed = [];
    foreach ($killsResults['shipsUsed'] as $result) {
        if ($result['_id']) $topShipsUsed[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topSystems = [];
    foreach ($killsResults['systems'] as $result) {
        if ($result['_id']) $topSystems[(int)$result['_id']] = (int)$result['count'];
    }
    
    $topRegions = [];
    foreach ($killsResults['regions'] as $result) {
        if ($result['_id']) $topRegions[(int)$result['_id']] = (int)$result['count'];
    }
    
    // Get top ships lost
    $pipeline = [
        ['$match' => $lossesQuery],
        ['$project' => [
            'shipTypeID' => ['$arrayElemAt' => ['$involved.shipTypeID', 0]]
        ]],
        ['$group' => ['_id' => '$shipTypeID', 'count' => ['$sum' => 1]]],
        ['$sort' => ['count' => -1]],
        ['$limit' => 5]
    ];
    $topShipsLost = [];
    foreach ($collection->aggregate($pipeline) as $result) {
        if ($result['_id']) $topShipsLost[(int)$result['_id']] = (int)$result['count'];
    }
    
    // Prepare data for template
    $data = [
        'type' => $type,
        'id' => $id,
        'info' => $info,
        'year' => 2025,
        'totalKills' => $totalKills,
        'totalLosses' => $totalLosses,
        'totalIskDestroyed' => $totalIskDestroyed,
        'totalIskLost' => $totalIskLost,
        'totalPointsDestroyed' => $totalPointsDestroyed,
        'totalPointsLost' => $totalPointsLost,
        'soloKills' => $soloKills,
        'biggestKill' => $biggestKill,
        'biggestKillValue' => $biggestKillValue,
        'biggestLoss' => $biggestLoss,
        'biggestLossValue' => $biggestLossValue,
        'monthlyKills' => $monthlyKills,
        'monthlyLosses' => $monthlyLosses,
        'topVictimCharacters' => $topVictimCharacters,
        'topVictimCorporations' => $topVictimCorporations,
        'topVictimAlliances' => $topVictimAlliances,
        'topKillerCharacters' => $topKillerCharacters,
        'topKillerCorporations' => $topKillerCorporations,
        'topKillerAlliances' => $topKillerAlliances,
        'topShipsUsed' => $topShipsUsed,
        'topShipsLost' => $topShipsLost,
        'topSystems' => $topSystems,
        'topRegions' => $topRegions,
        'efficiency' => $totalKills + $totalLosses > 0 ? round(($totalKills / ($totalKills + $totalLosses)) * 100, 1) : 0,
        'iskEfficiency' => $totalIskDestroyed + $totalIskLost > 0 ? round(($totalIskDestroyed / ($totalIskDestroyed + $totalIskLost)) * 100, 1) : 0,
    ];
    
    // Gather info for all entities in top lists
    $info_all = [
        'characterID' => [],
        'corporationID' => [],
        'allianceID' => [],
        'typeID' => [],
        'solarSystemID' => [],
        'regionID' => []
    ];
    
    // Get info for all victim characters
    foreach ($topVictimCharacters as $charId => $count) {
        $info_all['characterID'][$charId] = Info::getInfo('characterID', $charId);
    }
    
    // Get info for all victim corporations
    foreach ($topVictimCorporations as $corpId => $count) {
        $info_all['corporationID'][$corpId] = Info::getInfo('corporationID', $corpId);
    }
    
    // Get info for all victim alliances
    foreach ($topVictimAlliances as $alliId => $count) {
        $info_all['allianceID'][$alliId] = Info::getInfo('allianceID', $alliId);
    }
    
    // Get info for all killer characters
    foreach ($topKillerCharacters as $charId => $count) {
        $info_all['characterID'][$charId] = Info::getInfo('characterID', $charId);
    }
    
    // Get info for all killer corporations
    foreach ($topKillerCorporations as $corpId => $count) {
        $info_all['corporationID'][$corpId] = Info::getInfo('corporationID', $corpId);
    }
    
    // Get info for all killer alliances
    foreach ($topKillerAlliances as $alliId => $count) {
        $info_all['allianceID'][$alliId] = Info::getInfo('allianceID', $alliId);
    }
    
    // Get info for ships
    foreach ($topShipsUsed as $shipId => $count) {
        $info_all['typeID'][$shipId] = Info::getInfo('typeID', $shipId);
    }
    foreach ($topShipsLost as $shipId => $count) {
        $info_all['typeID'][$shipId] = Info::getInfo('typeID', $shipId);
    }
    
    // Get info for systems
    foreach ($topSystems as $systemId => $count) {
        $info_all['solarSystemID'][$systemId] = Info::getInfo('solarSystemID', $systemId);
    }
    
    // Get info for regions
    foreach ($topRegions as $regionId => $count) {
        $info_all['regionID'][$regionId] = Info::getInfo('regionID', $regionId);
    }
    
    // Add info to biggest kill/loss
    if ($biggestKill) {
        Info::addInfo($biggestKill);
        // Manually add date fields since Info::addInfo skips UTCDateTime objects
        if (isset($biggestKill['dttm'])) {
            $dttm = $biggestKill['dttm']->toDateTime()->getTimestamp();
            $biggestKill['unixtime'] = $dttm;
            $biggestKill['MonthDayYear'] = date('F j, Y', $dttm);
        }
        // Make system info accessible at top level
        if (isset($biggestKill['system']['solarSystemName'])) {
            $biggestKill['solarSystemName'] = $biggestKill['system']['solarSystemName'];
        }
    }
    if ($biggestLoss) {
        Info::addInfo($biggestLoss);
        // Manually add date fields since Info::addInfo skips UTCDateTime objects
        if (isset($biggestLoss['dttm'])) {
            $dttm = $biggestLoss['dttm']->toDateTime()->getTimestamp();
            $biggestLoss['unixtime'] = $dttm;
            $biggestLoss['MonthDayYear'] = date('F j, Y', $dttm);
        }
        // Make system info accessible at top level
        if (isset($biggestLoss['system']['solarSystemName'])) {
            $biggestLoss['solarSystemName'] = $biggestLoss['system']['solarSystemName'];
        }
    }
    
    $data['info_all'] = $info_all;
    $data['biggestKill'] = $biggestKill;
    $data['biggestLoss'] = $biggestLoss;
    
    // Store in keyvalues cache for 72 hours
    $ttl = 72 * 3600; // 72 hours in seconds
    $updatedTime = $mdb->now();
    $mdb->insertUpdate('keyvalues', 
        ['key' => $cacheKey], 
        [
            'value' => json_encode($data), 
            'expiresAt' => $mdb->now($ttl), 
            'updated' => $updatedTime
        ]
    );
    
    // Add generation time to the data being rendered
    $data['generationTime'] = $updatedTime;
    
    return $container->get('view')->render($response, 'recap2025.html', $data);
}
