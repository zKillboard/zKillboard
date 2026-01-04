<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$itemQueue = new RedisQueue('queueItemIndex');

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = (int) $itemQueue->pop();
    if ($killID > 0) {
        $killmail = Kills::getEsiKill($killID);
        updateItems($killID, $killmail['victim']['items']);
    } else sleep(1);
}

function updateItems($killID, $items, $inContainer = false) {
    global $mdb;

    // Collect all items first
    $toInsert = [];
    collectItems($killID, $items, $inContainer, $toInsert);

    if (empty($toInsert)) return;

    // Deduplicate by typeID
    $uniqueTypes = [];
    foreach ($toInsert as $record) {
        $uniqueTypes[$record['typeID']] = $record;
    }

    // Get the raw MongoDB collection
    $collection = $mdb->getCollection('itemmails');
    
    // Find existing typeIDs for this killID
    $existingRecords = $collection->find(
        ['killID' => $killID],
        ['projection' => ['typeID' => 1]]
    );
    
    $existingSet = [];
    foreach ($existingRecords as $record) {
        $existingSet[$record['typeID']] = true;
    }

    // Filter and prepare batch insert
    $batch = [];
    foreach ($uniqueTypes as $typeID => $record) {
        if (!isset($existingSet[$typeID])) {
            $batch[] = $record;
        }
    }

    // Batch insert if we have new items
    if (!empty($batch)) {
        $collection->insertMany($batch, ['ordered' => false]);
    }
}

function collectItems($killID, $items, $inContainer, &$toInsert) {
    foreach ($items as $item) {
        $typeID = (int) $item['item_type_id'];
        if ($typeID == 0) continue;

        $name = Info::getInfoField('typeID', $typeID, 'name');
        $isBlueprint = strpos($name, ' Blueprint') !== false;

        if ($isBlueprint && $inContainer == true) continue;
        if ($isBlueprint && $item['singleton'] != 0) continue;
        if ($isBlueprint && $killID < 21103361) continue;

        $toInsert[] = ['killID' => $killID, 'typeID' => $typeID];

        if (isset($item['items'])) {
            collectItems($killID, $item['items'], true, $toInsert);
        }
    }
}
