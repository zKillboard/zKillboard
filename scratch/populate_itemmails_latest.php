<?php

use MongoDB\Driver\Exception\BulkWriteException;

require_once "../init.php";

$limitKillmails = 25000000;
$keepPerItem = 100000;
$batchSize = 1000;

$killmails = $mdb->getCollection("killmails");
$itemmails = $mdb->getCollection("itemmails");

$typeCounts = loadTypeCounts($itemmails);
$processed = 0;
$lastKillID = 0;
$inserted = 0;
$skippedFull = 0;
$missingEsi = 0;

Util::out("Starting itemmails backfill from latest $limitKillmails killmails");
Util::out("Loaded " . sizeof($typeCounts) . " existing type counters");

while ($processed < $limitKillmails) {
    $query = [];
    if ($lastKillID > 0) {
        $query['killID'] = ['$lt' => $lastKillID];
    }

    $cursor = $killmails->find($query, [
        'projection' => ['killID' => 1, '_id' => 0],
        'sort' => ['killID' => -1],
        'limit' => min($batchSize, $limitKillmails - $processed)
    ]);
    $rows = iterator_to_array($cursor);
    if (sizeof($rows) == 0) {
        break;
    }

    foreach ($rows as $row) {
        $killID = (int) $row['killID'];
        $lastKillID = $killID;

        $killmail = Kills::getEsiKill($killID);
        if ($killmail == null || !isset($killmail['victim']['items'])) {
            $missingEsi++;
            $processed++;
            continue;
        }

        $records = [];
        collectItems($killID, $killmail['victim']['items'], false, $records);
        if (sizeof($records) == 0) {
            $processed++;
            continue;
        }

        $records = uniqueRecords($records);
        $existing = existingTypesForKill($itemmails, $killID);
        $batch = [];

        foreach ($records as $typeID => $record) {
            if (isset($existing[$typeID])) {
                continue;
            }
            if (!isset($typeCounts[$typeID])) {
                $typeCounts[$typeID] = 0;
            }
            if ($typeCounts[$typeID] >= $keepPerItem) {
                $skippedFull++;
                continue;
            }
            $batch[] = $record;
            $typeCounts[$typeID]++;
        }

        if (sizeof($batch) > 0) {
            try {
                $result = $itemmails->insertMany($batch, ['ordered' => false]);
                $inserted += $result->getInsertedCount();
            } catch (BulkWriteException $ex) {
                $inserted += $ex->getWriteResult()->getInsertedCount();
                Util::out("insertMany partially failed for killID $killID: " . $ex->getMessage());
            } catch (Exception $ex) {
                Util::out("insertMany failed for killID $killID: " . $ex->getMessage());
            }
        }

        $processed++;
    }

    Util::out("Processed $processed / $limitKillmails, inserted $inserted, skippedFull $skippedFull, missingEsi $missingEsi, lastKillID $lastKillID");
}

Util::out("Done. Processed $processed killmails, inserted $inserted item rows, skippedFull $skippedFull, missingEsi $missingEsi");

function loadTypeCounts($itemmails)
{
    $counts = [];
    $cursor = $itemmails->aggregate([
        ['$group' => ['_id' => '$typeID', 'count' => ['$sum' => 1]]]
    ], ['allowDiskUse' => true]);

    foreach ($cursor as $row) {
        $counts[(int) $row['_id']] = (int) $row['count'];
    }

    return $counts;
}

function existingTypesForKill($itemmails, $killID)
{
    $existing = [];
    $cursor = $itemmails->find(
        ['killID' => $killID],
        ['projection' => ['typeID' => 1, '_id' => 0]]
    );

    foreach ($cursor as $row) {
        $existing[(int) $row['typeID']] = true;
    }

    return $existing;
}

function uniqueRecords($records)
{
    $unique = [];
    foreach ($records as $record) {
        $unique[(int) $record['typeID']] = $record;
    }

    return $unique;
}

function collectItems($killID, $items, $inContainer, &$records)
{
    static $isBlueprintByTypeID = [];

    foreach ($items as $item) {
        $typeID = (int) $item['item_type_id'];
        if ($typeID == 0) continue;

        if (!isset($isBlueprintByTypeID[$typeID])) {
            $name = Info::getInfoField('typeID', $typeID, 'name');
            $isBlueprintByTypeID[$typeID] = strpos($name, ' Blueprint') !== false;
        }
        $isBlueprint = $isBlueprintByTypeID[$typeID];

        if ($isBlueprint && $inContainer == true) continue;
        if ($isBlueprint && $item['singleton'] != 0) continue;
        if ($isBlueprint && $killID < 21103361) continue;

        $records[] = ['killID' => $killID, 'typeID' => $typeID];

        if (isset($item['items'])) {
            collectItems($killID, $item['items'], true, $records);
        }
    }
}
