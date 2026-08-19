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
$topShipsCompleteKey = "zkb:topShipsCalculated:$today";

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
    $kvc->del($topShipsCompleteKey);
    exit();
}

foreach ($jobs as $job) {
    runRankJob($job);
    if (time() - $started > 60) exit();

    if ($job['epoch'] == 'recent' && $job['scope'] == 'all') {
        materializeShips($recentShipsCompleteKey, $today, 'ninetyDays', 'recentShips');
        if (time() - $started > 60) exit();
        $yearAgo = strtotime('-1 year');
        $firstKillID = MongoFilter::getFirstKillID(date('Y', $yearAgo), date('n', $yearAgo), date('j', $yearAgo));
        materializeShips($topShipsCompleteKey, $today, 'killmails', 'topShips', $firstKillID);
        if (time() - $started > 60) exit();
    }
}

function materializeShips($completeKey, $date, $collection, $field, $firstKillID = null)
{
    global $kvc, $mdb;

    if ($kvc->get($completeKey) == true) return;

    Util::out("Calculating $field");
    $runID = "$date:" . time();
    $updated = Mdb::now();
    // ScanAlyzer treats attacker and victim appearances alike, so this intentionally has no isVictim filter.
    $pipeline = [];
    if ($firstKillID != null) {
        $pipeline[] = ['$match' => ['killID' => ['$gte' => $firstKillID]]];
    }
    $pipeline = array_merge($pipeline, [
        ['$project' => [
            'involved.characterID' => 1,
            'involved.shipTypeID' => 1,
            'involved.groupID' => 1,
            'involved.isVictim' => 1,
            'zkb.totalValue' => 1,
        ]],
        ['$unwind' => '$involved'],
        ['$match' => ['involved.characterID' => ['$gt' => 0]]],
        ['$group' => [
            '_id' => ['characterID' => '$involved.characterID', 'shipTypeID' => '$involved.shipTypeID'],
            'groupID' => ['$first' => '$involved.groupID'],
            'appearances' => ['$sum' => 1],
            'kills' => ['$sum' => ['$cond' => [['$eq' => ['$involved.isVictim', false]], 1, 0]]],
            'losses' => ['$sum' => ['$cond' => [['$eq' => ['$involved.isVictim', true]], 1, 0]]],
            'isk' => ['$sum' => '$zkb.totalValue'],
        ]],
        ['$group' => [
            '_id' => '$_id.characterID',
            'ships' => ['$topN' => [
                'n' => 7,
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
        ]],
        ['$project' => [
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
                    ], 6]],
                    'as' => 'ship',
                    'cond' => ['$ne' => ['$$ship.groupID', 29]],
                ],
            ], 5]],
            $field . 'Updated' => ['$literal' => $updated],
            $field . 'RunID' => ['$literal' => $runID],
        ]],
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
    $types = collectPeriodRanks($job, $runID);
    finishRanks($job, $types, $runID);
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
            'locationID' => 1,
            'system' => 1,
            'zkb.points' => 1,
            'zkb.totalValue' => 1,
        ],
    ]);

    foreach ($rows as $row) {
        $seen = [];
        $killID = $row['killID'];
        $value = [
            'ships' => 1,
            'isk' => (int) ($row['zkb']['totalValue'] ?? 0),
            'points' => (int) ($row['zkb']['points'] ?? 0),
        ];

        foreach ($row['involved'] as $entity) {
            $isVictim = (bool) ($entity['isVictim'] ?? false);
            foreach ($entity as $type => $id) {
                if (strpos($type, 'ID') === false) continue;
                addPeriodStat($stats, $types, $allowed, $seen, $killID, $type, $id, $isVictim, $value);
            }

            foreach (periodLocationIds($row) as $type => $id) {
                addPeriodStat($stats, $types, $allowed, $seen, $killID, $type, $id, $isVictim, $value);
            }
        }
    }

    foreach ($stats as $type => $ids) {
        foreach ($ids as $id => $metrics) {
            if (!$allowed["$type:$id"] || $metrics['shipsDestroyed'] < $job['minDestroyed']) {
                $unranked[$type][$id] = $metrics;
                continue;
            }
            addScratchMetrics($job, $type, $id, $metrics);
        }
    }
    storeUnrankedPeriodStats($job, $unranked, $runID);

    return $types;
}

function addPeriodStat(&$stats, &$types, &$allowed, &$seen, $killID, $type, $id, $isVictim, $value)
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

function finishRanks($job, $types, $runID = null)
{
    foreach ($types as $type => $unused) {
        calculateOverallRanks($job, $type);
        storeRanks($job, $type, $runID);
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

function storeUnrankedPeriodStats($job, $stats, $runID)
{
    global $mdb;

    $collection = $mdb->getCollection('statistics');
    $now = Mdb::now();
    foreach ($stats as $type => $ids) {
        normalizeRankContainers($collection, $job, $type);
        $ops = [];
        foreach ($ids as $id => $metrics) {
            $ops[] = ['updateOne' => [
                ['type' => $type, 'id' => (int) $id],
                [
                    '$set' => ["rankings.{$job['epoch']}.{$job['scope']}" => [
                        'metrics' => $metrics,
                        'updated' => $now,
                        'runID' => $runID,
                    ]],
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

function storeRanks($job, $type, $runID = null)
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
        $ops[] = ['updateOne' => [
            ['type' => $type, 'id' => (int) $id],
            [
                '$set' => [
                    "rankings.{$job['epoch']}.{$job['scope']}" => $row,
                    "rankHistory.{$job['epoch']}.{$job['scope']}.{$job['date']}" => $row,
                ],
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
