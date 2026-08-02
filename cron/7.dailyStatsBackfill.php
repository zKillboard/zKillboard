<?php

require_once '../init.php';

$key = 'cron:7.dailyStatsBackfill';
if ($kvc->get($key) == true) exit();

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
$cursor = $mdb->getCollection('statistics')->find($query, [
    'projection' => ['_id' => 0, 'type' => 1, 'id' => 1, 'shipsDestroyed' => 1, 'shipsLost' => 1, 'months' => 1],
    'sort' => ['type' => 1, 'id' => 1],
]);

$entities = 0;
$days = 0;
$started = time();
$complete = true;
foreach ($cursor as $row) {
    if (time() - $started > 900) {
        $complete = false;
        break;
    }
    $queued = DailyStats::queueBackfill($row['type'], $row['id'], $row);
    $entities++;
    $days += $queued;
    if ($queued > 0) Util::out("Queued {$row['type']}:{$row['id']} daily stats backfill ($queued days)");
}

Util::out("Daily stats backfill " . ($complete ? 'complete' : 'paused') . ": $entities entities, $days days queued");
if ($complete) $kvc->set($key, true, 86400);
