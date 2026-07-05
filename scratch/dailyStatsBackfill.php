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
$backfillTypes = array_fill_keys($backfillTypes, true);

$cursor = $mdb->getCollection('killmails')->find([], [
    'projection' => [
        '_id' => 0,
        'killID' => 1,
        'sequence' => 1,
        'dttm' => 1,
        'involved' => 1,
        'system' => 1,
        'locationID' => 1,
        'labels' => 1,
    ],
    'sort' => ['sequence' => -1],
    'noCursorTimeout' => true,
]);

$killmailCount = 0;
$queuedCount = 0;
$flushedCount = 0;
$batch = [];
$batchLimit = 1000;

foreach ($cursor as $killmail) {
    $killmailCount++;
    $sequence = (int) @$killmail['sequence'];
    if ($sequence <= 0) {
        continue;
    }

    foreach (DailyStats::keysFromKillmail($killmail) as $key) {
        if (!isset($backfillTypes[$key['type']])) {
            continue;
        }
        if (!dailyStatsBackfillShouldPersist($key['type'], $key['id'])) {
            continue;
        }

        $batchKey = "{$key['type']}:{$key['id']}:{$key['day']}";
        if (!isset($batch[$batchKey]) || $batch[$batchKey]['sequence'] < $sequence) {
            $key['sequence'] = $sequence;
            $batch[$batchKey] = $key;
        }
        $queuedCount++;
    }

    if (count($batch) >= $batchLimit) {
        $flushedCount += flushDailyStatsBatch($batch);
        $batch = [];
    }

    if ($killmailCount % 10000 == 0) {
        echo "Scanned $killmailCount killmails; saw $queuedCount daily stat updates; flushed $flushedCount unique updates\n";
    }
}

$flushedCount += flushDailyStatsBatch($batch);

echo "Done: scanned $killmailCount killmails; saw $queuedCount daily stat updates; flushed $flushedCount unique updates\n";

function dailyStatsBackfillShouldPersist($type, $id)
{
    static $eligible = [];

    $cacheKey = "$type:$id";
    if (!isset($eligible[$cacheKey])) {
        $eligible[$cacheKey] = DailyStats::shouldPersist($type, $id);
    }

    return $eligible[$cacheKey];
}

function flushDailyStatsBatch($batch)
{
    global $mdb;

    if (count($batch) == 0) {
        return 0;
    }

    $ensureOps = [];
    $updateOps = [];
    foreach ($batch as $row) {
        $month = substr($row['day'], 0, 7);
        $dayField = substr($row['day'], 8, 2);
        $sequence = (int) $row['sequence'];
        $key = ['type' => $row['type'], 'id' => $row['id'], DailyStats::MONTH_FIELD => $month];
        $ensureOps[] = ['updateOne' => [
            $key,
            ['$setOnInsert' => $key + ['created' => time()]],
            ['upsert' => true],
        ]];
        $updateOps[] = ['updateOne' => [
            $key + ['$or' => [
                [$dayField => ['$exists' => false]],
                ["$dayField.sequence" => ['$exists' => false]],
                ["$dayField.sequence" => ['$lt' => $sequence]],
            ]],
            ['$addToSet' => ['updates' => "{$row['day']}:$sequence"]],
        ]];
    }

    $collection = $mdb->getCollection(DailyStats::COLLECTION);
    $collection->bulkWrite($ensureOps, ['ordered' => false]);
    $collection->bulkWrite($updateOps, ['ordered' => false]);
    return count($batch);
}
