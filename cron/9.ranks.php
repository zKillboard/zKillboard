<?php

require_once '../init.php';

const RANK_TYPES = [
    'allianceID',
    'characterID',
    'constellationID',
    'corporationID',
    'factionID',
    'groupID',
    'locationID',
    'regionID',
    'solarSystemID',
    'shipTypeID',
];

const RANK_METRICS = ['shipsDestroyed', 'shipsLost', 'iskDestroyed', 'iskLost', 'pointsDestroyed', 'pointsLost'];

$today = date('Ymd');
$alltimeDate = date('Ymd', time() - (3600 * 4));
$started = time();
$recentShipsCompleteKey = "zkb:recentShipsCalculated:$today";
$affiliatesCompleteKey = "zkb:affiliatesCalculated:$today";
$associatesCompleteKey = "zkb:associatesCalculated:$today";
$topShipsCompleteKey = "zkb:topShipsCalculated:$today";
$fcCompleteKey = "zkb:fcCalculated:$today";
$awoxCountsCompleteKey = "zkb:awoxCountsCalculated:$today";
$baitCompleteKey = "zkb:baitCalculated:$today";
$cynoCompleteKey = "zkb:cynoCalculated:$today";
$gankerCompleteKey = "zkb:gankerCalculated:$today";

$jobs = [
    alltimeJob('all', $alltimeDate, 'zkb:alltimeRanksCalculated:%s:%s'),
    alltimeJob('solo', $alltimeDate, 'zkb:alltimeSoloRanksCalculated:%s:%s'),
    periodJob('recent', 'all', 'ninetyDays', $today, "zkb:recentRanksCalculated:$today", 86400, ['npc' => false, 'labels' => 'pvp']),
    periodJob('recent', 'solo', 'ninetyDays', $today, "zkb:recentRanksSoloCalculated:$today", 86400, ['npc' => false, 'labels' => 'pvp', 'solo' => true]),
    periodJob('weekly', 'all', 'oneWeek', $today, 'zkb:weeklyRanksCalculated', 3600, ['npc' => false, 'labels' => 'pvp']),
    periodJob('weekly', 'solo', 'oneWeek', $today, 'zkb:weeklyRanksSoloCalculated', 3600, ['npc' => false, 'labels' => 'pvp', 'solo' => true]),
];

if (hasArg('--reset-complete') || hasArg('--recalculate')) {
    resetCompleteKeys($jobs);
    $kvc->del($recentShipsCompleteKey);
    $kvc->del($affiliatesCompleteKey);
    $kvc->del($associatesCompleteKey);
    $kvc->del($topShipsCompleteKey);
    $kvc->del($fcCompleteKey);
    $kvc->del($awoxCountsCompleteKey);
    $kvc->del($baitCompleteKey);
    $kvc->del($cynoCompleteKey);
    $kvc->del($gankerCompleteKey);
    exit();
}

foreach ($jobs as $job) {
    runRankJob($job);
    if (time() - $started > 60) exit();

    if ($job['epoch'] == 'recent' && $job['scope'] == 'all') {
        materializeShips($recentShipsCompleteKey, $today, 'ninetyDays', 'recentShips');
        if (time() - $started > 60) exit();
        materializeAffiliates($affiliatesCompleteKey, $today);
        if (time() - $started > 60) exit();
        materializeAssociates($associatesCompleteKey, $today);
        if (time() - $started > 60) exit();
        $yearAgo = strtotime('-1 year');
        $firstKillID = MongoFilter::getFirstKillID(date('Y', $yearAgo), date('n', $yearAgo), date('j', $yearAgo));
        materializeShips($topShipsCompleteKey, $today, 'killmails', 'topShips', $firstKillID, $fcCompleteKey);
        if (time() - $started > 60) exit();
        materializeAwoxCounts($awoxCountsCompleteKey, $today, $firstKillID);
        if (time() - $started > 60) exit();
        materializeBait($baitCompleteKey, $today, $firstKillID);
        if (time() - $started > 60) exit();
        materializeCyno($cynoCompleteKey, $today, $firstKillID);
        if (time() - $started > 60) exit();
        materializeGankers($gankerCompleteKey, $today, $firstKillID);
        if (time() - $started > 60) exit();
    }
}

function materializeAffiliates($completeKey, $date)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating affiliates');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $pipeline = [
        ['$match' => ['labels' => 'pvp']],
        ['$project' => [
            'attackers' => ['$setUnion' => [[
                '$map' => [
                    'input' => ['$filter' => [
                        'input' => '$involved',
                        'as' => 'pilot',
                        'cond' => ['$and' => [
                            ['$eq' => ['$$pilot.isVictim', false]],
                            ['$gt' => [['$ifNull' => ['$$pilot.characterID', 0]], 3999999]],
                        ]],
                    ]],
                    'as' => 'pilot',
                    'in' => [
                        'characterID' => '$$pilot.characterID',
                        'allianceID' => ['$ifNull' => ['$$pilot.allianceID', 0]],
                    ],
                ],
            ], []]],
            'allianceIDs' => ['$setUnion' => [[
                '$map' => [
                    'input' => ['$filter' => [
                        'input' => '$involved',
                        'as' => 'pilot',
                        'cond' => ['$and' => [
                            ['$eq' => ['$$pilot.isVictim', false]],
                            ['$gt' => [['$ifNull' => ['$$pilot.allianceID', 0]], 0]],
                        ]],
                    ]],
                    'as' => 'pilot',
                    'in' => '$$pilot.allianceID',
                ],
            ], []]],
        ]],
        ['$unwind' => '$attackers'],
        ['$set' => ['affiliateAllianceIDs' => ['$setDifference' => [
            '$allianceIDs',
            [['$ifNull' => ['$attackers.allianceID', 0]]],
        ]]]],
        ['$unwind' => '$affiliateAllianceIDs'],
        ['$group' => [
            '_id' => ['characterID' => '$attackers.characterID', 'allianceID' => '$affiliateAllianceIDs'],
            'sharedKills' => ['$sum' => 1],
        ]],
        ['$match' => ['sharedKills' => ['$gte' => 3]]],
        ['$project' => [
            '_id' => 0,
            'characterID' => '$_id.characterID',
            'allianceID' => '$_id.allianceID',
            'sharedKills' => 1,
        ]],
        ['$group' => [
            '_id' => '$characterID',
            'affiliates' => ['$topN' => [
                'n' => 25,
                'sortBy' => ['sharedKills' => -1, 'allianceID' => 1],
                'output' => ['allianceID' => '$allianceID', 'sharedKills' => '$sharedKills'],
            ]],
        ]],
        ['$project' => [
            '_id' => 0,
            'type' => ['$literal' => 'characterID'],
            'id' => '$_id',
            'affiliates' => 1,
            'affiliatesUpdated' => ['$literal' => $updated],
            'affiliatesRunID' => ['$literal' => $runID],
        ]],
        ['$merge' => [
            'into' => 'statistics',
            'on' => ['type', 'id'],
            'whenMatched' => 'merge',
            'whenNotMatched' => 'insert',
        ]],
    ];

    iterator_to_array($mdb->getCollection('ninetyDays')->aggregate($pipeline, ['allowDiskUse' => true]));
    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'affiliatesRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['affiliates' => 1, 'affiliatesUpdated' => 1, 'affiliatesRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeAssociates($completeKey, $date)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating associates');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $characterIDs = ['$setUnion' => [[
        '$map' => [
            'input' => ['$filter' => [
                'input' => '$involved',
                'as' => 'pilot',
                'cond' => ['$and' => [
                    ['$eq' => ['$$pilot.isVictim', false]],
                    ['$gt' => [['$ifNull' => ['$$pilot.characterID', 0]], 3999999]],
                ]],
            ]],
            'as' => 'pilot',
            'in' => '$$pilot.characterID',
        ],
    ], []]];
    $pipeline = [
        ['$match' => ['labels' => 'pvp']],
        ['$project' => ['firstCharacterIDs' => $characterIDs, 'secondCharacterIDs' => $characterIDs]],
        ['$unwind' => '$firstCharacterIDs'],
        ['$unwind' => '$secondCharacterIDs'],
        ['$match' => ['$expr' => ['$lt' => ['$firstCharacterIDs', '$secondCharacterIDs']]]],
        ['$group' => [
            '_id' => ['firstCharacterID' => '$firstCharacterIDs', 'secondCharacterID' => '$secondCharacterIDs'],
            'sharedKills' => ['$sum' => 1],
        ]],
        ['$match' => ['sharedKills' => ['$gte' => 3]]],
        ['$project' => [
            '_id' => 0,
            'sharedKills' => 1,
            'directions' => [
                ['characterID' => '$_id.firstCharacterID', 'associateID' => '$_id.secondCharacterID'],
                ['characterID' => '$_id.secondCharacterID', 'associateID' => '$_id.firstCharacterID'],
            ],
        ]],
        ['$unwind' => '$directions'],
        ['$group' => [
            '_id' => '$directions.characterID',
            'associates' => ['$topN' => [
                'n' => 50,
                'sortBy' => ['sharedKills' => -1, 'directions.associateID' => 1],
                'output' => ['characterID' => '$directions.associateID', 'sharedKills' => '$sharedKills'],
            ]],
        ]],
        ['$project' => [
            '_id' => 0,
            'type' => ['$literal' => 'characterID'],
            'id' => '$_id',
            'associates' => 1,
            'associatesUpdated' => ['$literal' => $updated],
            'associatesRunID' => ['$literal' => $runID],
        ]],
        ['$merge' => [
            'into' => 'statistics',
            'on' => ['type', 'id'],
            'whenMatched' => 'merge',
            'whenNotMatched' => 'insert',
        ]],
    ];

    iterator_to_array($mdb->getCollection('ninetyDays')->aggregate($pipeline, ['allowDiskUse' => true]));
    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'associatesRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['associates' => 1, 'associatesUpdated' => 1, 'associatesRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeShips($completeKey, $date, $collection, $field, $firstKillID = null, $fcCompleteKey = null)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true && ($fcCompleteKey == null || $kvc->get($fcCompleteKey) == true)) return;

    Util::out("Calculating $field");
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $includeFc = $fcCompleteKey != null;
    // ScanAlyzer treats attacker and victim appearances alike, so this intentionally has no isVictim filter.
    $pipeline = [];
    if ($firstKillID != null) {
        $pipeline[] = ['$match' => ['killID' => ['$gte' => $firstKillID]]];
    }
    $project = [
        'involved.characterID' => 1,
        'involved.shipTypeID' => 1,
        'involved.groupID' => 1,
        'involved.isVictim' => 1,
        'zkb.totalValue' => 1,
    ];
    $shipGroup = [
        '_id' => ['characterID' => '$involved.characterID', 'shipTypeID' => '$involved.shipTypeID'],
        'groupID' => ['$first' => '$involved.groupID'],
        'appearances' => ['$sum' => 1],
        'kills' => ['$sum' => ['$cond' => [['$eq' => ['$involved.isVictim', false]], 1, 0]]],
        'losses' => ['$sum' => ['$cond' => [['$eq' => ['$involved.isVictim', true]], 1, 0]]],
        'isk' => ['$sum' => '$zkb.totalValue'],
    ];
    $characterGroup = [
        '_id' => '$_id.characterID',
        'ships' => ['$topN' => [
            'n' => 11,
            'sortBy' => ['appearances' => -1, 'isk' => -1],
            'output' => [
                'shipTypeID' => '$_id.shipTypeID',
                'groupID' => '$groupID',
                'appearances' => '$appearances',
                'kills' => '$kills',
                'losses' => '$losses',
                'isk' => '$isk',
            ],
        ]],
    ];
    $finalProject = [
        '_id' => 0,
        'type' => ['$literal' => 'characterID'],
        'id' => '$_id',
        $field => ['$slice' => [[
            '$filter' => [
                'input' => ['$slice' => [[
                    '$filter' => [
                        'input' => '$ships',
                        'as' => 'ship',
                        'cond' => ['$gt' => ['$$ship.shipTypeID', 0]],
                    ],
                ], 10]],
                'as' => 'ship',
                'cond' => ['$ne' => ['$$ship.groupID', 29]],
            ],
        ], 9]],
        $field . 'Updated' => ['$literal' => $updated],
        $field . 'RunID' => ['$literal' => $runID],
    ];
    $fcStages = [];
    if ($includeFc) {
        $project['attackerCount'] = 1;
        $project['npc'] = 1;
        $shipGroup['monitorAppearances'] = ['$sum' => ['$cond' => [[
            '$and' => [
                ['$eq' => ['$npc', false]],
                ['$eq' => ['$involved.isVictim', false]],
                ['$eq' => ['$involved.shipTypeID', 45534]],
            ],
        ], 1, 0]]];
        $shipGroup['commandShipAppearances'] = ['$sum' => ['$cond' => [[
            '$and' => [
                ['$eq' => ['$npc', false]],
                ['$eq' => ['$involved.isVictim', false]],
                ['$eq' => ['$involved.groupID', 540]],
            ],
        ], 1, 0]]];
        $shipGroup['largeFleetAppearances'] = ['$sum' => ['$cond' => [[
            '$and' => [
                ['$eq' => ['$npc', false]],
                ['$eq' => ['$involved.isVictim', false]],
                ['$gte' => ['$attackerCount', 25]],
            ],
        ], 1, 0]]];
        $characterGroup['monitorAppearances'] = ['$sum' => '$monitorAppearances'];
        $characterGroup['commandShipAppearances'] = ['$sum' => '$commandShipAppearances'];
        $characterGroup['largeFleetAppearances'] = ['$sum' => '$largeFleetAppearances'];
        $fcStages = [
            ['$set' => ['fcScore' => ['$add' => [
                ['$min' => [100, ['$multiply' => ['$monitorAppearances', 20]]]],
                ['$min' => [40, ['$multiply' => ['$commandShipAppearances', 2]]]],
                ['$min' => [20, ['$divide' => ['$largeFleetAppearances', 5]]]],
            ]]]],
            ['$set' => ['fcLevel' => ['$switch' => [
                'branches' => [
                    ['case' => ['$gte' => ['$fcScore', 100]], 'then' => 'high'],
                    ['case' => ['$gte' => ['$fcScore', 60]], 'then' => 'medium'],
                    ['case' => ['$gte' => ['$fcScore', 35]], 'then' => 'low'],
                ],
                'default' => null,
            ]]]],
        ];
        $finalProject['fc'] = ['$cond' => [
            ['$ne' => ['$fcLevel', null]],
            [
                'level' => '$fcLevel',
                'score' => ['$round' => ['$fcScore', 0]],
                'monitorAppearances' => '$monitorAppearances',
                'commandShipAppearances' => '$commandShipAppearances',
                'largeFleetAppearances' => '$largeFleetAppearances',
            ],
            '$$REMOVE',
        ]];
        $finalProject['fcUpdated'] = ['$cond' => [
            ['$ne' => ['$fcLevel', null]],
            ['$literal' => $updated],
            '$$REMOVE',
        ]];
        $finalProject['fcRunID'] = ['$cond' => [
            ['$ne' => ['$fcLevel', null]],
            ['$literal' => $runID],
            '$$REMOVE',
        ]];
    }
    $pipeline = array_merge($pipeline, [
        ['$project' => $project],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.characterID' => ['$gt' => 0]]],
        ['$group' => $shipGroup],
        ['$group' => $characterGroup],
    ], $fcStages, [
        ['$project' => $finalProject],
        ['$merge' => [
            'into' => 'statistics',
            'on' => ['type', 'id'],
            'whenMatched' => 'merge',
            'whenNotMatched' => 'insert',
        ]],
    ]);

    iterator_to_array($mdb->getCollection($collection)->aggregate($pipeline, ['allowDiskUse' => true]));
    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', $field . 'RunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => [$field => 1, $field . 'Updated' => 1, $field . 'RunID' => 1]]
    );
    if ($includeFc) {
        $mdb->getCollection('statistics')->updateMany(
            ['type' => 'characterID', 'fcRunID' => ['$exists' => true, '$ne' => $runID]],
            ['$unset' => ['fc' => 1, 'fcUpdated' => 1, 'fcRunID' => 1]]
        );
        $kvc->setex($fcCompleteKey, 86400, 'true');
    }
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeAwoxCounts($completeKey, $date, $firstKillID)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating awox counts');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $pipeline = [
        ['$match' => ['awox' => true, 'killID' => ['$gte' => $firstKillID]]],
        ['$project' => ['involved.characterID' => 1, 'involved.finalBlow' => 1]],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.characterID' => ['$gt' => 0], 'involved.finalBlow' => true]],
        ['$group' => ['_id' => '$involved.characterID', 'awoxCount' => ['$sum' => 1]]],
        ['$project' => [
            '_id' => 0,
            'type' => ['$literal' => 'characterID'],
            'id' => '$_id',
            'awoxCount' => 1,
            'awoxCountUpdated' => ['$literal' => $updated],
            'awoxCountRunID' => ['$literal' => $runID],
        ]],
        ['$merge' => [
            'into' => 'statistics',
            'on' => ['type', 'id'],
            'whenMatched' => 'merge',
            'whenNotMatched' => 'insert',
        ]],
    ];

    iterator_to_array($mdb->getCollection('killmails')->aggregate($pipeline, ['allowDiskUse' => true]));
    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'awoxCountRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['awoxCount' => 1, 'awoxCountUpdated' => 1, 'awoxCountRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeBait($completeKey, $date, $firstKillID)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating bait');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $gatePositionsBySystem = [];
    $cursor = $mdb->getCollection('sde_mapStargates')->find(
        [],
        ['projection' => ['_id' => 0, 'solarSystemID' => 1, 'position' => 1]]
    );
    foreach ($cursor as $row) {
        $systemID = (int) ($row['solarSystemID'] ?? 0);
        $position = $row['position'] ?? null;
        if ($systemID <= 0 || !isset($position['x'], $position['y'], $position['z'])) continue;
        $gatePositionsBySystem[$systemID][] = [
            'x' => (float) $position['x'],
            'y' => (float) $position['y'],
            'z' => (float) $position['z'],
        ];
    }
    $gateDistanceSquared = pow(149597870700 * 0.1, 2);
    $pendingBySystem = [];
    $baitCounts = [];
    $processBatch = function ($events) use (&$pendingBySystem, &$baitCounts, $mdb, $gatePositionsBySystem, $gateDistanceSquared) {
        $potentialBySystem = [];
        foreach ($pendingBySystem as $systemID => $losses) {
            foreach ($losses as $loss) $potentialBySystem[$systemID][] = $loss['time'];
        }

        $interestingKillIDs = [];
        $followUpKillIDs = [];
        foreach ($events as $event) {
            $systemID = $event['systemID'];
            $potential = [];
            foreach ($potentialBySystem[$systemID] ?? [] as $time) {
                if ($event['time'] - $time <= 300) $potential[] = $time;
            }
            if (sizeof($potential)) $potentialBySystem[$systemID] = $potential;
            else unset($potentialBySystem[$systemID]);

            if ($event['isFollowUp'] && sizeof($potential)) {
                $interestingKillIDs[$event['killID']] = $event['killID'];
                $followUpKillIDs[$event['killID']] = $event['killID'];
            }
            if ($event['isCheapLoss']) {
                $interestingKillIDs[$event['killID']] = $event['killID'];
                $potentialBySystem[$systemID][] = $event['time'];
            }
        }

        $positions = [];
        foreach (array_chunk(array_values($interestingKillIDs), 1000) as $killIDs) {
            $cursor = $mdb->getCollection('esimails')->find(
                ['killmail_id' => ['$in' => $killIDs]],
                ['projection' => ['_id' => 0, 'killmail_id' => 1, 'victim.position' => 1]]
            );
            foreach ($cursor as $row) {
                $position = $row['victim']['position'] ?? null;
                if (!isset($position['x'], $position['y'], $position['z'])) continue;
                $positions[(int) $row['killmail_id']] = [
                    'x' => (float) $position['x'],
                    'y' => (float) $position['y'],
                    'z' => (float) $position['z'],
                ];
            }
        }

        $presentByKillID = [];
        foreach (array_chunk(array_values($followUpKillIDs), 1000) as $killIDs) {
            $cursor = $mdb->getCollection('killmails')->find(
                ['killID' => ['$in' => $killIDs]],
                ['projection' => ['_id' => 0, 'killID' => 1, 'involved.characterID' => 1]]
            );
            foreach ($cursor as $row) {
                $killID = (int) $row['killID'];
                foreach ($row['involved'] ?? [] as $involved) {
                    $characterID = (int) ($involved['characterID'] ?? 0);
                    if ($characterID > 0) $presentByKillID[$killID][$characterID] = true;
                }
            }
        }

        foreach ($events as $event) {
            $systemID = $event['systemID'];
            $position = $positions[$event['killID']] ?? null;
            if (isset($pendingBySystem[$systemID])) {
                $pending = [];
                foreach ($pendingBySystem[$systemID] as $loss) {
                    $age = $event['time'] - $loss['time'];
                    if ($age > 300) continue;
                    $isMatch = false;
                    if ($event['isFollowUp'] && $age >= 0 && $position != null && !isset($presentByKillID[$event['killID']][$loss['characterID']])) {
                        $x = $position['x'] - $loss['position']['x'];
                        $y = $position['y'] - $loss['position']['y'];
                        $z = $position['z'] - $loss['position']['z'];
                        // ESI positions are meters, so compare against 250 km squared.
                        $isMatch = ($x * $x) + ($y * $y) + ($z * $z) <= 62500000000;
                    }
                    if ($isMatch) {
                        $characterID = $loss['characterID'];
                        $baitCounts[$characterID] = ($baitCounts[$characterID] ?? 0) + 1;
                    } else {
                        $pending[] = $loss;
                    }
                }
                if (sizeof($pending)) $pendingBySystem[$systemID] = $pending;
                else unset($pendingBySystem[$systemID]);
            }

            if ($event['isCheapLoss'] && $position != null) {
                $nearGate = false;
                foreach ($gatePositionsBySystem[$systemID] ?? [] as $gatePosition) {
                    $x = $position['x'] - $gatePosition['x'];
                    $y = $position['y'] - $gatePosition['y'];
                    $z = $position['z'] - $gatePosition['z'];
                    if (($x * $x) + ($y * $y) + ($z * $z) <= $gateDistanceSquared) {
                        $nearGate = true;
                        break;
                    }
                }
                if ($nearGate) continue;
                $pendingBySystem[$systemID][] = [
                    'characterID' => $event['characterID'],
                    'time' => $event['time'],
                    'position' => $position,
                ];
            }
        }
    };

    $cursor = $mdb->getCollection('killmails')->find(
        ['killID' => ['$gte' => $firstKillID]],
        [
            'projection' => [
                '_id' => 0,
                'killID' => 1,
                'dttm' => 1,
                'npc' => 1,
                'attackerCount' => 1,
                'categoryID' => 1,
                'system.solarSystemID' => 1,
                'system.security' => 1,
                'zkb.fittedValue' => 1,
                'involved' => ['$elemMatch' => ['isVictim' => true]],
            ],
            'sort' => ['killID' => 1],
            'batchSize' => 1000,
            'noCursorTimeout' => true,
        ]
    );

    $events = [];
    foreach ($cursor as $row) {
        $systemID = (int) ($row['system']['solarSystemID'] ?? 0);
        $dttm = $row['dttm'] ?? null;
        if ($systemID <= 0 || !($dttm instanceof MongoDB\BSON\UTCDateTime)) continue;
        $isPvp = ($row['npc'] ?? true) === false;
        $victim = $row['involved'][0] ?? [];
        $characterID = (int) ($victim['characterID'] ?? 0);
        $events[] = [
            'killID' => (int) $row['killID'],
            'time' => $dttm->toDateTime()->getTimestamp(),
            'systemID' => $systemID,
            'characterID' => $characterID,
            'isFollowUp' => $isPvp && (int) ($row['attackerCount'] ?? 0) >= 3,
            'isCheapLoss' => $isPvp
                && $characterID > 0
                && (float) ($row['system']['security'] ?? 1) < 0.45
                && (int) ($row['categoryID'] ?? 0) == 6
                && (int) ($victim['groupID'] ?? 0) != 29
                && (float) ($row['zkb']['fittedValue'] ?? 10000000) < 10000000,
        ];
        if (sizeof($events) == 1000) {
            $processBatch($events);
            $events = [];
        }
    }
    if (sizeof($events)) $processBatch($events);

    $operations = [];
    foreach ($baitCounts as $characterID => $count) {
        if ($count < 4) continue;
        $level = $count >= 12 ? 'high' : ($count >= 7 ? 'medium' : 'low');
        $operations[] = ['updateOne' => [
            ['type' => 'characterID', 'id' => $characterID],
            ['$set' => [
                'bait' => ['level' => $level, 'count' => $count],
                'baitUpdated' => $updated,
                'baitRunID' => $runID,
            ]],
            ['upsert' => true],
        ]];
        if (sizeof($operations) == 1000) {
            $mdb->getCollection('statistics')->bulkWrite($operations, ['ordered' => false]);
            $operations = [];
        }
    }
    if (sizeof($operations)) $mdb->getCollection('statistics')->bulkWrite($operations, ['ordered' => false]);

    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'baitRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['bait' => 1, 'baitUpdated' => 1, 'baitRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeCyno($completeKey, $date, $firstKillID)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating cyno usage');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $cynoTypeIDs = [21096, 28646, 52694];
    $candidateKillIDs = [];
    $cursor = $mdb->getCollection('itemmails')->find(
        ['typeID' => ['$in' => $cynoTypeIDs], 'killID' => ['$gte' => $firstKillID]],
        ['projection' => ['_id' => 0, 'killID' => 1]]
    );
    foreach ($cursor as $row) {
        $killID = (int) ($row['killID'] ?? 0);
        if ($killID > 0) $candidateKillIDs[$killID] = $killID;
    }

    $cynoCounts = [];
    foreach (array_chunk(array_values($candidateKillIDs), 1000) as $killIDs) {
        $cursor = $mdb->getCollection('esimails')->find(
            ['killmail_id' => ['$in' => $killIDs]],
            ['projection' => [
                '_id' => 0,
                'killmail_id' => 1,
                'victim.character_id' => 1,
                'victim.items' => 1,
            ]]
        );
        foreach ($cursor as $row) {
            $characterID = (int) ($row['victim']['character_id'] ?? 0);
            if ($characterID <= 0) continue;
            $typeID = 0;
            foreach ($row['victim']['items'] ?? [] as $item) {
                $itemTypeID = (int) ($item['item_type_id'] ?? 0);
                $flag = (int) ($item['flag'] ?? 0);
                if ($flag >= 27 && $flag <= 34 && in_array($itemTypeID, $cynoTypeIDs, true)) {
                    $typeID = $itemTypeID;
                    break;
                }
            }
            if ($typeID == 0) continue;
            if (!isset($cynoCounts[$characterID])) {
                $cynoCounts[$characterID] = ['count' => 0, 'standard' => 0, 'covert' => 0, 'industrial' => 0];
            }
            $cynoCounts[$characterID]['count']++;
            if ($typeID == 21096) $cynoCounts[$characterID]['standard']++;
            else if ($typeID == 28646) $cynoCounts[$characterID]['covert']++;
            else if ($typeID == 52694) $cynoCounts[$characterID]['industrial']++;
        }
    }

    $operations = [];
    foreach ($cynoCounts as $characterID => $counts) {
        $operations[] = ['updateOne' => [
            ['type' => 'characterID', 'id' => $characterID],
            ['$set' => [
                'cyno' => $counts,
                'cynoUpdated' => $updated,
                'cynoRunID' => $runID,
            ]],
            ['upsert' => true],
        ]];
        if (sizeof($operations) == 1000) {
            $mdb->getCollection('statistics')->bulkWrite($operations, ['ordered' => false]);
            $operations = [];
        }
    }
    if (sizeof($operations)) $mdb->getCollection('statistics')->bulkWrite($operations, ['ordered' => false]);

    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'cynoRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['cyno' => 1, 'cynoUpdated' => 1, 'cynoRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function materializeGankers($completeKey, $date, $firstKillID)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out('Calculating ganker counts');
    $runID = "$date:" . time();
    $updated = Mdb::now();
    $pipeline = [
        ['$match' => ['ganked' => true, 'killID' => ['$gte' => $firstKillID]]],
        ['$project' => ['involved.characterID' => 1, 'involved.isVictim' => 1]],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.characterID' => ['$gt' => 0], 'involved.isVictim' => false]],
        ['$group' => ['_id' => '$involved.characterID', 'gankerCount' => ['$sum' => 1]]],
        ['$match' => ['gankerCount' => ['$gte' => 10]]],
        ['$project' => [
            '_id' => 0,
            'type' => ['$literal' => 'characterID'],
            'id' => '$_id',
            'gankerCount' => 1,
            'gankerUpdated' => ['$literal' => $updated],
            'gankerRunID' => ['$literal' => $runID],
        ]],
        ['$merge' => [
            'into' => 'statistics',
            'on' => ['type', 'id'],
            'whenMatched' => 'merge',
            'whenNotMatched' => 'insert',
        ]],
    ];

    iterator_to_array($mdb->getCollection('killmails')->aggregate($pipeline, ['allowDiskUse' => true]));
    $mdb->getCollection('statistics')->updateMany(
        ['type' => 'characterID', 'gankerRunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => ['gankerCount' => 1, 'gankerUpdated' => 1, 'gankerRunID' => 1]]
    );
    $kvc->setex($completeKey, 86400, 'true');
}

function hasArg($arg)
{
    global $argv;

    return isset($argv) && in_array($arg, $argv, true);
}

function resetCompleteKeys($jobs)
{
    global $kvc;

    foreach ($jobs as $job) {
        if ($job['epoch'] == 'alltime') {
            foreach (RANK_TYPES as $type) {
                $kvc->del(sprintf($job['completeKey'], $type, $job['date']));
            }
        } else {
            $kvc->del($job['completeKey']);
        }

        if (($job['waitForKey'] ?? null) != null) {
            $kvc->del($job['waitForKey']);
        }
    }
}

function alltimeJob($scope, $date, $completeKey)
{
    return [
        'epoch' => 'alltime',
        'scope' => $scope,
        'date' => $date,
        'source' => 'statistics',
        'completeKey' => $completeKey,
        'completeTtl' => 87000,
        'scratchTtl' => 100000,
        'sourceSuffix' => $scope == 'solo' ? 'Solo' : '',
        'minDestroyed' => 100,
        'zeroMode' => 'alltime',
    ];
}

function periodJob($epoch, $scope, $source, $date, $completeKey, $completeTtl, $query, $waitForKey = null)
{
    return [
        'epoch' => $epoch,
        'scope' => $scope,
        'date' => $date,
        'source' => $source,
        'completeKey' => $completeKey,
        'completeTtl' => $completeTtl,
        'scratchTtl' => $epoch == 'weekly' ? 9000 : 86400,
        'sourceSuffix' => '',
        'minDestroyed' => $epoch == 'weekly' ? 1 : 10,
        'zeroMode' => $epoch == 'weekly' ? 'skip' : 'zero',
        'query' => $query,
        'waitForKey' => $waitForKey,
    ];
}

function runRankJob($job)
{
    global $kvc;

    if (($job['waitForKey'] ?? null) != null && $kvc->get($job['waitForKey']) != 'true') return;

    if ($job['epoch'] == 'alltime') {
        $type = nextAlltimeType($job);
        if ($type == null) return;
        Util::out("Calculating {$job['scope']} alltime ranks for $type");
        $types = [$type => true];
        collectAlltimeRanks($job, $type);
        finishRanks($job, $types);
        $kvc->setex(sprintf($job['completeKey'], $type, $job['date']), $job['completeTtl'], true);
        return;
    }

    if ($kvc->get($job['completeKey']) == true) return;

    Util::out("Calculating {$job['scope']} {$job['epoch']} ranks");
    $runID = "{$job['date']}:" . time();
    [$types, $periodLabels] = collectPeriodRanks($job, $runID);
    finishRanks($job, $types, $runID, $periodLabels);
    clearOldPeriodLabels($job, $runID);
    $kvc->setex($job['completeKey'], $job['completeTtl'], 'true');
}

function nextAlltimeType($job)
{
    global $kvc;

    foreach (RANK_TYPES as $type) {
        if ($kvc->get(sprintf($job['completeKey'], $type, $job['date'])) != true) return $type;
    }

    return null;
}

function collectAlltimeRanks($job, $type)
{
    global $mdb;

    $field = sourceMetric('shipsDestroyed', $job);
    $rows = $mdb->getCollection($job['source'])->find(['type' => $type]);
    foreach ($rows as $row) {
        $id = $row['id'];
        if (($row[$field] ?? 0) < $job['minDestroyed']) continue;
        if (!rankEntityAllowed($type, $id)) continue;

        addScratchMetrics($job, $type, $id, [
            'shipsDestroyed' => $row[sourceMetric('shipsDestroyed', $job)] ?? 0,
            'shipsLost' => $row[sourceMetric('shipsLost', $job)] ?? 0,
            'iskDestroyed' => $row[sourceMetric('iskDestroyed', $job)] ?? 0,
            'iskLost' => $row[sourceMetric('iskLost', $job)] ?? 0,
            'pointsDestroyed' => $row[sourceMetric('pointsDestroyed', $job)] ?? 0,
            'pointsLost' => $row[sourceMetric('pointsLost', $job)] ?? 0,
        ]);
    }
}

function collectPeriodRanks($job, $runID)
{
    global $mdb;

    $types = [];
    $stats = [];
    $unranked = [];
    $allowed = [];
    $parameters = $job['query'];
    $query = MongoFilter::buildQuery($parameters);
    $rows = $mdb->getCollection($job['source'])->find($query, [
        'projection' => [
            'involved' => 1,
            'killID' => 1,
            'labels' => 1,
            'locationID' => 1,
            'system' => 1,
            'zkb.points' => 1,
            'zkb.totalValue' => 1,
        ],
    ]);

    foreach ($rows as $row) {
        $seen = [];
        $killID = $row['killID'];
        $statLabels = [];
        if ($job['scope'] == 'all') {
            foreach ((array) ($row['labels'] ?? []) as $label) {
                $label = (string) $label;
                if (str_starts_with($label, 'loc:') || str_starts_with($label, 'tz:') || str_starts_with($label, '#:') || $label == 'solo') $statLabels[$label] = true;
            }
            if ((int) ($row['system']['regionID'] ?? 0) == 10000070) {
                foreach (array_keys($statLabels) as $label) {
                    if (str_starts_with($label, 'loc:')) unset($statLabels[$label]);
                }
                $statLabels['loc:pochven'] = true;
            }
        }
        $statLabels = array_keys($statLabels);
        $value = [
            'ships' => 1,
            'isk' => (int) ($row['zkb']['totalValue'] ?? 0),
            'points' => (int) ($row['zkb']['points'] ?? 0),
        ];

        foreach ($row['involved'] as $entity) {
            $isVictim = (bool) ($entity['isVictim'] ?? false);
            foreach ($entity as $type => $id) {
                if (strpos($type, 'ID') === false) continue;
                addPeriodStat($stats, $types, $allowed, $seen, $killID, $type, $id, $isVictim, $value, $statLabels);
            }

            foreach (periodLocationIds($row) as $type => $id) {
                addPeriodStat($stats, $types, $allowed, $seen, $killID, $type, $id, $isVictim, $value, $statLabels);
            }
        }
    }

    $periodLabels = [];
    foreach ($stats as $type => $ids) {
        foreach ($ids as $id => $metrics) {
            if ($job['scope'] == 'all') $periodLabels[$type][$id] = $metrics['labels'] ?? [];
            unset($stats[$type][$id]['labels']);
            unset($metrics['labels']);
            if (!$allowed["$type:$id"] || $metrics['shipsDestroyed'] < $job['minDestroyed']) {
                $unranked[$type][$id] = $metrics;
                continue;
            }
            addScratchMetrics($job, $type, $id, $metrics);
        }
    }
    storeUnrankedPeriodStats($job, $unranked, $runID, $periodLabels);

    return [$types, $periodLabels];
}

function addPeriodStat(&$stats, &$types, &$allowed, &$seen, $killID, $type, $id, $isVictim, $value, $labels)
{
    if ($id === null || $id === '') return;

    $id = (int) $id;
    $seenKey = "$killID:$type:$id:" . ($isVictim ? 'l' : 'k');
    if (isset($seen[$seenKey])) return;
    $seen[$seenKey] = true;

    $types[$type] = true;
    if (!isset($allowed["$type:$id"])) {
        $allowed["$type:$id"] = rankEntityAllowed($type, $id);
    }

    if (!isset($stats[$type][$id])) {
        $stats[$type][$id] = [
            'shipsDestroyed' => 0,
            'shipsLost' => 0,
            'iskDestroyed' => 0,
            'iskLost' => 0,
            'pointsDestroyed' => 0,
            'pointsLost' => 0,
        ];
    }

    $suffix = $isVictim ? 'Lost' : 'Destroyed';
    $stats[$type][$id]["ships$suffix"] += $value['ships'];
    $stats[$type][$id]["isk$suffix"] += $value['isk'];
    $stats[$type][$id]["points$suffix"] += $value['points'];
    foreach ($labels as $label) {
        if (!isset($stats[$type][$id]['labels'][$label])) {
            $stats[$type][$id]['labels'][$label] = ['shipsDestroyed' => 0, 'shipsLost' => 0];
        }
        $stats[$type][$id]['labels'][$label]["ships$suffix"]++;
    }
}

function periodLocationIds($row)
{
    $ids = [];
    if (isset($row['locationID'])) $ids['locationID'] = $row['locationID'];
    if (isset($row['system']['solarSystemID'])) $ids['solarSystemID'] = $row['system']['solarSystemID'];
    if (isset($row['system']['constellationID'])) $ids['constellationID'] = $row['system']['constellationID'];
    if (isset($row['system']['regionID'])) $ids['regionID'] = $row['system']['regionID'];
    return $ids;
}

function addScratchMetrics($job, $type, $id, $metrics)
{
    global $redis;

    $multi = $redis->multi();
    $key = scratchKey($job, $type);
    foreach (RANK_METRICS as $metric) {
        $value = max($job['epoch'] == 'alltime' ? 1 : 0, (int) $metrics[$metric]);
        $multi->zAdd("$key:$metric", $value, $id);
        $multi->expire("$key:$metric", $job['scratchTtl']);
    }
    $multi->exec();
}

function finishRanks($job, $types, $runID = null, $periodLabels = [])
{
    foreach ($types as $type => $unused) {
        calculateOverallRanks($job, $type);
        storeRanks($job, $type, $runID, $periodLabels[$type] ?? []);
        purgeScratchRanks($job, $type);
    }
}

function calculateOverallRanks($job, $type)
{
    global $redis;

    $key = scratchKey($job, $type);
    $max = $redis->zCard("$key:shipsDestroyed");
    $redis->del($key);

    $it = null;
    while ($matches = $redis->zScan("$key:shipsDestroyed", $it)) {
        foreach ($matches as $id => $unusedScore) {
            $ships = rankEfficiency($redis->zScore("$key:shipsDestroyed", $id), $redis->zScore("$key:shipsLost", $id), $job);
            $iskDestroyed = $redis->zScore("$key:iskDestroyed", $id);
            $isk = rankEfficiency($iskDestroyed, $redis->zScore("$key:iskLost", $id), $job);
            $points = rankEfficiency($redis->zScore("$key:pointsDestroyed", $id), $redis->zScore("$key:pointsLost", $id), $job);

            if ($job['zeroMode'] == 'alltime' && $iskDestroyed == 0) continue;
            if ($ships === null || $isk === null || $points === null) continue;

            $avg = ceil((
                rankCheck($max, $redis->zRevRank("$key:shipsDestroyed", $id)) +
                rankCheck($max, $redis->zRevRank("$key:iskDestroyed", $id)) +
                rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id))
            ) / 3);
            $score = ceil($avg / ((1 + $ships + $isk + $points) / 4));
            $redis->zAdd($key, $score, $id);
            $redis->expire($key, $job['scratchTtl']);
        }
    }
}

function storeUnrankedPeriodStats($job, $stats, $runID, $periodLabels)
{
    global $mdb;

    $collection = $mdb->getCollection('statistics');
    $now = Mdb::now();
    foreach ($stats as $type => $ids) {
        normalizeRankContainers($collection, $job, $type);
        $ops = [];
        foreach ($ids as $id => $metrics) {
            $set = ["rankings.{$job['epoch']}.{$job['scope']}" => [
                'metrics' => $metrics,
                'updated' => $now,
                'runID' => $runID,
            ]] + periodLabelUpdate($job, $periodLabels[$type][$id] ?? [], $now, $runID);
            $ops[] = ['updateOne' => [
                ['type' => $type, 'id' => (int) $id],
                [
                    '$set' => $set,
                    '$setOnInsert' => ['type' => $type, 'id' => (int) $id],
                ],
                ['upsert' => true],
            ]];
            flushRankBulk($collection, $ops, false, function () use ($collection, $job, $type) {
                normalizeRankContainers($collection, $job, $type);
            });
        }
        flushRankBulk($collection, $ops, true, function () use ($collection, $job, $type) {
            normalizeRankContainers($collection, $job, $type);
        });
    }
}

function storeRanks($job, $type, $runID = null, $periodLabels = [])
{
    global $mdb, $redis;

    $collection = $mdb->getCollection('statistics');
    $key = scratchKey($job, $type);
    $now = Mdb::now();
    $ops = [];

    if ($runID == null) $runID = "{$job['date']}:" . time();
    normalizeRankContainers($collection, $job, $type);

    foreach (rankIds($key) as $id) {
        $row = rankRowFromRedis($job, $type, $id, $key, $now, $runID);
        $set = [
            "rankings.{$job['epoch']}.{$job['scope']}" => $row,
            "rankHistory.{$job['epoch']}.{$job['scope']}.{$job['date']}" => $row,
        ] + periodLabelUpdate($job, $periodLabels[$id] ?? [], $now, $runID);
        $ops[] = ['updateOne' => [
            ['type' => $type, 'id' => (int) $id],
            [
                '$set' => $set,
                '$setOnInsert' => ['type' => $type, 'id' => (int) $id],
            ],
            ['upsert' => true],
        ]];
        flushRankBulk($collection, $ops, false, function () use ($collection, $job, $type) {
            normalizeRankContainers($collection, $job, $type);
        });
    }

    flushRankBulk($collection, $ops, true, function () use ($collection, $job, $type) {
        normalizeRankContainers($collection, $job, $type);
    });
    clearOldRankHistory($collection, $job, $type);

    if ($job['epoch'] != 'alltime') {
        clearOldRanks($collection, $job, $type, $runID);
    }
}

function periodLabelUpdate($job, $labels, $updated, $runID)
{
    if ($job['epoch'] == 'alltime' || $job['scope'] != 'all') return [];

    $field = $job['epoch'] . 'Labels';
    return [
        $field => $labels,
        $field . 'Updated' => $updated,
        $field . 'RunID' => $runID,
    ];
}

function clearOldPeriodLabels($job, $runID)
{
    global $mdb;

    if ($job['epoch'] == 'alltime' || $job['scope'] != 'all') return;

    $field = $job['epoch'] . 'Labels';
    $mdb->getCollection('statistics')->updateMany(
        [$field . 'RunID' => ['$exists' => true, '$ne' => $runID]],
        ['$unset' => [$field => 1, $field . 'Updated' => 1, $field . 'RunID' => 1]]
    );
}

function normalizeRankContainers($collection, $job, $type)
{
    foreach (['rankings', 'rankHistory'] as $field) {
        $epochPath = "$field.{$job['epoch']}";
        $scopePath = "$epochPath.{$job['scope']}";

        $collection->updateMany(
            ['type' => $type, $epochPath => ['$type' => 'array']],
            ['$set' => [$epochPath => (object) []]]
        );

        $collection->updateMany(
            ['type' => $type, $scopePath => ['$type' => 'array']],
            ['$set' => [$scopePath => (object) []]]
        );
    }
}

function clearOldRankHistory($collection, $job, $type)
{
    $unset = [];
    for ($days = 8; $days <= 30; $days++) {
        $date = date('Ymd', time() - ($days * 86400));
        $unset["rankHistory.{$job['epoch']}.{$job['scope']}.$date"] = 1;
    }

    $collection->updateMany(
        ['type' => $type, "rankHistory.{$job['epoch']}.{$job['scope']}" => ['$exists' => true]],
        ['$unset' => $unset]
    );
}

function clearOldRanks($collection, $job, $type, $runID)
{
    $collection->updateMany(
        [
            'type' => $type,
            "rankings.{$job['epoch']}.{$job['scope']}" => ['$exists' => true],
            "rankings.{$job['epoch']}.{$job['scope']}.runID" => ['$ne' => $runID],
        ],
        ['$unset' => ["rankings.{$job['epoch']}.{$job['scope']}" => 1]]
    );
}

function rankRowFromRedis($job, $type, $id, $key, $updated, $runID)
{
    global $redis;

    $metrics = [];
    $ranks = ['overall' => rankCheck(null, $redis->zRank($key, $id))];
    foreach (RANK_METRICS as $metric) {
        $metrics[$metric] = $redis->zScore("$key:$metric", $id);
        $ranks[$metric] = rankCheck(null, $redis->zRevRank("$key:$metric", $id));
    }

    return [
        'metrics' => $metrics,
        'ranks' => $ranks,
        'overallScore' => $redis->zScore($key, $id),
        'updated' => $updated,
        'runID' => $runID,
    ];
}

function purgeScratchRanks($job, $type)
{
    global $redis;

    $key = scratchKey($job, $type);
    $multi = $redis->multi();
    $multi->del($key);
    foreach (RANK_METRICS as $metric) {
        $multi->del("$key:$metric");
    }
    $multi->exec();
}

function rankEntityAllowed($type, $id)
{
    global $mdb;

    if (in_array($type, ['characterID', 'corporationID', 'allianceID'])) {
        if ($mdb->findField('information', 'disqualified', ['type' => $type, 'id' => $id]) === true) return false;
    }

    return !($type == 'corporationID' && $id <= 1999999);
}

function rankEfficiency($destroyed, $lost, $job)
{
    $total = $destroyed + $lost;
    if ($total == 0) return $job['zeroMode'] == 'zero' ? 0 : null;
    return $destroyed / $total;
}

function rankIds($key)
{
    global $redis;

    $ids = [];
    $it = null;
    while ($matches = $redis->zScan($key, $it)) {
        foreach ($matches as $id => $score) $ids[] = $id;
    }
    return $ids;
}

function scratchKey($job, $type)
{
    return "zkb:ranks:build:{$job['epoch']}:{$job['scope']}:$type:{$job['date']}";
}

function sourceMetric($metric, $job)
{
    return $metric . $job['sourceSuffix'];
}

function rankCheck($max, $rank)
{
    if ($rank === false || $rank === null) return $max ?? '-';
    return $rank + 1;
}

function flushRankBulk($collection, &$ops, $force = false, $retryNormalize = null)
{
    if (sizeof($ops) == 0 || (!$force && sizeof($ops) < 500)) return;
    try {
        $collection->bulkWrite($ops);
    } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
        if ($retryNormalize == null || strpos($e->getMessage(), 'Cannot create field') === false) throw $e;
        $retryNormalize();
        $collection->bulkWrite($ops);
    }
    $ops = [];
}
