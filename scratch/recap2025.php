<?php

require_once '../init.php';

$minute = date("Hi") + 0;
$cursor = $mdb->getCollection("statistics")->find(['recap2025' => false]);
foreach ($cursor as $next) {
    echo date("Hi") . " $minute " . $next['type'] . " " . $next['id'] . " ";
    flush();
    echo generateRecap2025($next['type'], $next['id'], $next);
    if (date("Hi") > $minute) break;
}


function generateRecap2025($type, $id, $statistics)
{
	global $mdb, $redis, $kvc;
    $cacheKey = "recap2025:$type:$id";

    $skip = !($type == "characterID" || $type == "corporationID" || $type == "allianceID");
    $skip |= ($type == "corporationID" && $id <= 1999999);
    if ($skip) return "skipped\n";

    if ($redis->set($cacheKey, "false", ['nx', 'ex' => 3600]) !== true) "in progress or already calced...\n";

    // Get entity info
    $typeField = $type;
    $info = Info::getInfoDetails($typeField, $id);
    
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
    
    // Get biggest kill/loss from killmails collection
    $startTime = new \MongoDB\BSON\UTCDateTime(strtotime('2025-01-01 00:00:00') * 1000);
    $endTime = new \MongoDB\BSON\UTCDateTime(strtotime('2025-12-31 23:59:59') * 1000);
    
    $collection = $mdb->getCollection('killmails');
    
    // Get biggest kill (any type, not just ships)
    $biggestKillDoc = $collection->findOne(
        [
            'involved' => ['$elemMatch' => [$typeField => $id, 'isVictim' => false]],
            'dttm' => ['$gte' => $startTime, '$lte' => $endTime]
        ],
        ['sort' => ['zkb.totalValue' => -1]]
    );
    $biggestKill = $biggestKillDoc ? json_decode(json_encode($biggestKillDoc), true) : null;
    $biggestKillValue = (float) (@$biggestKill['zkb']['totalValue'] ?? 0);
    
    // Get biggest loss (any type, not just ships)
    $biggestLossDoc = $collection->findOne(
        [
            'involved' => ['$elemMatch' => [$typeField => $id, 'isVictim' => true]],
            'dttm' => ['$gte' => $startTime, '$lte' => $endTime]
        ],
        ['sort' => ['zkb.totalValue' => -1]]
    );
    $biggestLoss = $biggestLossDoc ? json_decode(json_encode($biggestLossDoc), true) : null;
    $biggestLossValue = (float) (@$biggestLoss['zkb']['totalValue'] ?? 0);
    
    // Custom aggregation function for top entities
    $getTopEntities = function($entityField, $isVictim, $limit = 5) use ($mdb, $typeField, $id, $startTime, $endTime) {
        $collection = $mdb->getCollection('killmails');
        
        // Build match query
        $matchQuery = [
            'involved' => ['$elemMatch' => [$typeField => $id, 'isVictim' => !$isVictim]],
            'dttm' => ['$gte' => $startTime, '$lte' => $endTime]
        ];
        
        // Build aggregation pipeline
        $pipeline = [
            ['$match' => $matchQuery],
            ['$project' => [
                'killmail_id' => 1,
                'targets' => ['$filter' => [
                    'input' => '$involved',
                    'as' => 'inv',
                    'cond' => ['$eq' => ['$$inv.isVictim', $isVictim]]
                ]]
            ]],
            ['$unwind' => '$targets'],
            ['$group' => [
                '_id' => ['entityId' => '$targets.'.$entityField, 'killmail_id' => '$killmail_id'],
                'entityId' => ['$first' => '$targets.'.$entityField]
            ]],
            ['$match' => ['entityId' => ['$ne' => null], '_id.entityId' => ['$ne' => null], '_id.entityId' => ['$ne' => 0]]],
            ['$group' => ['_id' => '$entityId', 'count' => ['$sum' => 1]]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit]
        ];
        
        $results = [];
        foreach ($collection->aggregate($pipeline, ['allowDiskUse' => true]) as $result) {
            if ($result['_id']) {
                $results[(int)$result['_id']] = (int)$result['count'];
            }
        }
        return $results;
    };
    
    // Get top victims (who we killed)
    $topVictimCharacters = $getTopEntities('characterID', true);
    $topVictimCorporations = $getTopEntities('corporationID', true);
    $topVictimAlliances = $getTopEntities('allianceID', true);
    
    // Get top killers (who killed us)
    $topKillerCharacters = $getTopEntities('characterID', false);
    $topKillerCorporations = $getTopEntities('corporationID', false);
    $topKillerAlliances = $getTopEntities('allianceID', false);
    
    // Custom function for ships - get the ship the entity was flying
    $getTopShips = function($isKills, $limit = 5) use ($mdb, $typeField, $id, $startTime, $endTime) {
        $collection = $mdb->getCollection('killmails');
        
        $matchQuery = [
            'involved' => ['$elemMatch' => [$typeField => $id, 'isVictim' => !$isKills]],
            'dttm' => ['$gte' => $startTime, '$lte' => $endTime]
        ];
        
        $pipeline = [
            ['$match' => $matchQuery],
            ['$project' => [
                'ship' => ['$filter' => [
                    'input' => '$involved',
                    'as' => 'inv',
                    'cond' => ['$and' => [
                        ['$eq' => ['$$inv.'.$typeField, $id]],
                        ['$eq' => ['$$inv.isVictim', !$isKills]]
                    ]]
                ]]
            ]],
            ['$unwind' => '$ship'],
            ['$group' => ['_id' => '$ship.shipTypeID', 'count' => ['$sum' => 1]]],
            ['$match' => ['_id' => ['$ne' => null], '_id' => ['$ne' => 0]]],
            ['$sort' => ['count' => -1]],
            ['$limit' => $limit]
        ];
        
        $results = [];
        foreach ($collection->aggregate($pipeline, ['allowDiskUse' => true]) as $result) {
            if ($result['_id']) {
                $results[(int)$result['_id']] = (int)$result['count'];
            }
        }
        return $results;
    };
    
    $topShipsUsed = $getTopShips(true);
    $topShipsLost = $getTopShips(false);
    
    // Get top systems and regions using Stats::getTop
    $statsParams = [
        $typeField => [$id],
        'year' => 2025,
        'limit' => 5,
        'cacheTime' => 0
    ];
    
    $convertToIdCount = function($results, $idField) {
        $output = [];
        foreach ($results as $item) {
            if (isset($item[$idField]) && isset($item['kills'])) {
                $output[$item[$idField]] = $item['kills'];
            }
        }
        return $output;
    };
    
    $topSystems = $convertToIdCount(Stats::getTop('solarSystemID', array_merge($statsParams, ['kills' => true]), false, false), 'solarSystemID');
    $topRegions = $convertToIdCount(Stats::getTop('regionID', array_merge($statsParams, ['kills' => true]), false, false), 'regionID');
    
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
        // Manually add date fields - dttm is now an array after json_decode
        if (isset($biggestKill['dttm']['$date']['$numberLong'])) {
            $dttm = (int)($biggestKill['dttm']['$date']['$numberLong'] / 1000);
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
        // Manually add date fields - dttm is now an array after json_decode
        if (isset($biggestLoss['dttm']['$date']['$numberLong'])) {
            $dttm = (int)($biggestLoss['dttm']['$date']['$numberLong'] / 1000);
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
    $mdb->set("statistics", ['type' => $type, 'id' => $id], ['recap2025' => true]);
    
    // Add generation time to the data being rendered
    $data['generationTime'] = $updatedTime;

    $redis->setex($cacheKey, 86400 * 3, "true");
	return "complete\n";
}
