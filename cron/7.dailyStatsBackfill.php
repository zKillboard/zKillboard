<?php

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
    'dailyStatsBackfillComplete' => ['$ne' => true],
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

$minute = date('Hi');
$queuedEntities = 0;
$queuedDays = 0;
$completedScan = true;
$cursor = $mdb->getCollection('statistics')->find($query, [
    'projection' => ['type' => 1, 'id' => 1],
    'sort' => ['type' => 1, 'id' => 1],
]);

foreach ($cursor as $row) {
    if ($minute != date('Hi') || $redis->get("zkb:reinforced") == true) {
        $completedScan = false;
        break;
    }

    $type = DailyStats::normalizeType($row['type'] ?? '');
    $id = $type == 'label' ? (string) ($row['id'] ?? '') : (int) ($row['id'] ?? 0);
    if (!isset(DailyStats::$types[$type]) || $id === '' || ($type != 'label' && $id == 0)) {
        continue;
    }

    $days = DailyStats::populateBackfill($type, $id);
    if ($days <= 0) {
        continue;
    }

    $queuedEntities++;
    $queuedDays += $days;
    Util::out("Queued daily stats backfill for $type:$id ($days days)");
}

if ($completedScan) {
    Util::out("Daily stats backfill scan complete: $queuedEntities entities, $queuedDays days queued");
    $kvc->setex($key, 86400, true);
} else {
    Util::out("Daily stats backfill scan paused: $queuedEntities entities, $queuedDays days queued");
}
