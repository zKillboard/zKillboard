<?php

require_once '../init.php';

$backfillTypes = [
    'characterID',
    // 'corporationID',
    // 'allianceID',
    // 'factionID',
    // 'shipTypeID',
    // 'groupID',
    // 'solarSystemID',
    // 'constellationID',
    // 'regionID',
    // 'locationID',
    // 'label',
];

$cursor = $mdb->getCollection('statistics')->aggregate([
    ['$match' => [
        'type' => ['$in' => $backfillTypes],
        'dailyStatsBackfillQueued' => ['$ne' => true],
    ]],
    ['$project' => [
        '_id' => 0,
        'type' => 1,
        'id' => 1,
        'total' => ['$add' => [
            ['$ifNull' => ['$shipsDestroyed', 0]],
            ['$ifNull' => ['$shipsLost', 0]],
        ]],
    ]],
    ['$match' => ['total' => ['$gte' => DailyStats::PERSIST_MIN_TOTAL]]],
], ['allowDiskUse' => true]);

$seenCount = 0;
$queuedCount = 0;
$queuedDays = 0;
foreach ($cursor as $row) {
    $seenCount++;
    $queued = DailyStats::queueBackfill($row['type'], $row['id']);
    if ($queued > 0) {
        $queuedCount++;
        $queuedDays += $queued;
    }

    if ($seenCount % 1000 == 0) {
        echo "Scanned $seenCount statistics rows; queued $queuedCount entities and $queuedDays daily updates\n";
    }
}

echo "Done: scanned $seenCount statistics rows; queued $queuedCount entities and $queuedDays daily updates\n";
