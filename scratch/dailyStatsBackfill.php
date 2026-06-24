<?php

require_once '../init.php';

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
        $batchKey = DailyStats::queueValue($key['type'], $key['id'], $key['day']);
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

function flushDailyStatsBatch($batch)
{
    global $mdb;

    if (count($batch) == 0) {
        return 0;
    }

    $existingQuery = ['$or' => []];
    foreach ($batch as $row) {
        $existingQuery['$or'][] = ['type' => $row['type'], 'id' => $row['id'], 'day' => $row['day']];
    }

    $existing = [];
    $cursor = $mdb->getCollection(DailyStats::COLLECTION)->find($existingQuery, [
        'projection' => ['_id' => 0, 'type' => 1, 'id' => 1, 'day' => 1],
    ]);
    foreach ($cursor as $row) {
        $existing[DailyStats::queueValue($row['type'], $row['id'], $row['day'])] = true;
    }

    $ops = [];
    foreach ($batch as $batchKey => $row) {
        if (isset($existing[$batchKey])) {
            continue;
        }

        $key = ['type' => $row['type'], 'id' => $row['id'], 'day' => $row['day']];
        $sequence = (int) $row['sequence'];
        $ops[] = ['updateOne' => [
            $key,
            [
                '$setOnInsert' => $key + ['created' => time()],
                '$max' => ['update' => $sequence],
            ],
            ['upsert' => true],
        ]];
    }

    if (count($ops) == 0) {
        return 0;
    }

    $mdb->getCollection(DailyStats::COLLECTION)->bulkWrite($ops, ['ordered' => false]);
    return count($ops);
}
