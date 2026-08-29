<?php
exit();

require_once '../init.php';

if ($redis->get("zkb:reinforced") == true) {
    exit();
}

$day = gmdate('Y-m-d');
$key = "zkb:dailyStatsBackfill:$day";
if ($kvc->get($key) == true) {
    exit();
}

$query = [
    '$expr' => [
        '$gte' => [
            ['$add' => [
                ['$ifNull' => ['$shipsDestroyed', 0]],
                ['$ifNull' => ['$shipsLost', 0]],
            ]],
            DailyStats::PERSIST_MIN_TOTAL,
        ],
    ],
    'type' => ['$in' => array_keys(DailyStats::$types)],
];

$queuedEntities = 0;
$queuedDays = 0;
$auditMissingEntities = 0;
$completedScan = true;
$statsMonthlyProjection = [DailyStats::MONTH_FIELD => 1, 'updates' => 1];
for ($dayNum = 1; $dayNum <= 31; $dayNum++) {
    $statsMonthlyProjection[sprintf('%02d', $dayNum) . '.sequence'] = 1;
}
$time = time();

$incompleteQuery = $query;
$incompleteQuery['dailyStatsBackfillComplete'] = ['$ne' => true];
$incompleteQuery['$or'] = [
    ['dailyStatsBackfillQueued' => ['$ne' => true]],
    ['dailyStatsBackfillQueuedAt' => ['$exists' => false]],
    ['dailyStatsBackfillQueuedAt' => ['$lt' => time() - 86400]],
];
$completedQuery = $query;
$completedQuery['dailyStatsBackfillComplete'] = true;

foreach ([['query' => $incompleteQuery, 'audit' => false], ['query' => $completedQuery, 'audit' => true]] as $scan) {
    $projection = ['type' => 1, 'id' => 1];
    if ($scan['audit']) {
        $projection['months'] = 1;
    }

    $cursor = $mdb->getCollection('statistics')->find($scan['query'], [
        'projection' => $projection,
        'sort' => ['type' => 1, 'id' => 1],
    ]);

    foreach ($cursor as $row) {
        if ((time() - $time) > 900) {
            $completedScan = false;
            break 2;
        }

        $type = DailyStats::normalizeType($row['type'] ?? '');
        $id = $type == 'label' ? (string) ($row['id'] ?? '') : (int) ($row['id'] ?? 0);
        if (!isset(DailyStats::$types[$type]) || $id === '' || ($type != 'label' && $id == 0)) {
            continue;
        }

        if ($scan['audit']) {
            $expectedMonths = [];
            foreach ((array) ($row['months'] ?? []) as $monthStats) {
                $monthStats = is_object($monthStats) ? (array) $monthStats : (array) $monthStats;
                $year = (int) ($monthStats['year'] ?? 0);
                $month = (int) ($monthStats['month'] ?? 0);
                $total = (int) ($monthStats['shipsDestroyed'] ?? 0) + (int) ($monthStats['shipsLost'] ?? 0);
                if ($year <= 0 || $month <= 0 || $month > 12 || $total <= 0) {
                    continue;
                }
                $expectedMonths[sprintf('%04d-%02d', $year, $month)] = true;
            }
            if (count($expectedMonths) == 0) {
                continue;
            }

            $existingMonths = [];
            $monthlyDocs = $mdb->getCollection(DailyStats::COLLECTION)->find(['type' => $type, 'id' => $id], [
                'projection' => $statsMonthlyProjection,
            ]);
            foreach ($monthlyDocs as $monthlyDoc) {
                $month = (string) ($monthlyDoc[DailyStats::MONTH_FIELD] ?? '');
                if ($month == '') {
                    continue;
                }
                $hasData = count((array) ($monthlyDoc['updates'] ?? [])) > 0;
                for ($dayNum = 1; !$hasData && $dayNum <= 31; $dayNum++) {
                    $dayField = sprintf('%02d', $dayNum);
                    $hasData = isset($monthlyDoc[$dayField]) && (is_array($monthlyDoc[$dayField]) || is_object($monthlyDoc[$dayField]));
                }
                if ($hasData) {
                    $existingMonths[$month] = true;
                }
            }

            $missingMonths = array_keys(array_diff_key($expectedMonths, $existingMonths));
            if (count($missingMonths) == 0) {
                continue;
            }
            $auditMissingEntities++;
            Util::out("Daily stats audit found missing months for $type:$id (" . implode(', ', array_slice($missingMonths, 0, 5)) . (count($missingMonths) > 5 ? ', ...' : '') . ")");
        }

        $days = DailyStats::populateBackfill($type, $id, true);
        if ($days <= 0) {
            continue;
        }

        $queuedEntities++;
        $queuedDays += $days;
        Util::out("Queued daily stats backfill for $type:$id ($days days)");
    }
}

if ($completedScan) {
    Util::out("Daily stats backfill scan complete: $queuedEntities entities, $queuedDays days queued, $auditMissingEntities completed entities with missing months");
    $kvc->setex($key, 86400, true);
} else {
    Util::out("Daily stats backfill scan paused: $queuedEntities entities, $queuedDays days queued, $auditMissingEntities completed entities with missing months");
}
