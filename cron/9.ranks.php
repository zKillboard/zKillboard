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
$hour = time() - (time() % 3600);
$soloHour = (time() - 1800) - ((time() - 1800) % 3600);
$started = time();

$jobs = [
    alltimeJob('all', $alltimeDate, 'zkb:alltimeRanksCalculated:%s:%s'),
    alltimeJob('solo', $alltimeDate, 'zkb:alltimeSoloRanksCalculated:%s:%s'),
    periodJob('recent', 'all', 'ninetyDays', $today, "zkb:recentRanksCalculated:$today", 86400, ['npc' => false, 'labels' => 'pvp']),
    periodJob('recent', 'solo', 'ninetyDays', $today, "zkb:recentRanksSoloCalculated:$today", 86400, ['npc' => false, 'labels' => 'pvp', 'solo' => true]),
    periodJob('weekly', 'solo', 'oneWeek', $today, "zkb:weeklyRanksSoloCalculated:$soloHour", 1200, ['npc' => false, 'categoryID' => 6, 'solo' => true]),
    periodJob('weekly', 'all', 'oneWeek', $today, "zkb:weeklyRanksCalculated:$hour", 1200, ['npc' => false, 'categoryID' => 6], "zkb:weeklyRanksSoloCalculated:$hour"),
];

if (hasArg('--reset-complete') || hasArg('--recalculate')) {
    resetCompleteKeys($jobs);
    exit();
}

foreach ($jobs as $job) {
    runRankJob($job);
    if (time() - $started > 60) exit();
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
    $types = collectPeriodRanks($job);
    finishRanks($job, $types);
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

function collectPeriodRanks($job)
{
    global $mdb;

    $types = [];
    $stats = [];
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
            if ($metrics['shipsDestroyed'] < $job['minDestroyed']) continue;
            addScratchMetrics($job, $type, $id, $metrics);
        }
    }

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
    if (!$allowed["$type:$id"]) return;

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

function finishRanks($job, $types)
{
    foreach ($types as $type => $unused) {
        calculateOverallRanks($job, $type);
        storeRanks($job, $type);
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

function storeRanks($job, $type)
{
    global $mdb, $redis;

    $collection = $mdb->getCollection('statistics');
    $key = scratchKey($job, $type);
    $now = Mdb::now();
    $ops = [];

    $runID = "{$job['date']}:" . time();

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
        flushRankBulk($collection, $ops);
    }

    flushRankBulk($collection, $ops, true);
    clearOldRankHistory($collection, $job, $type);

    if ($job['epoch'] != 'alltime') {
        clearOldRanks($collection, $job, $type, $runID);
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

function flushRankBulk($collection, &$ops, $force = false)
{
    if (sizeof($ops) == 0 || (!$force && sizeof($ops) < 500)) return;
    $collection->bulkWrite($ops);
    $ops = [];
}
