<?php

require_once "../init.php";

if (date('Hi') != "1000") exit();

$keepPerItem = 100000;
$collection = $mdb->getCollection("itemmails");
$typeIDs = $collection->distinct("typeID");
$totalTypes = sizeof($typeIDs);
$totalRemoved = 0;
$current = 0;

foreach ($typeIDs as $typeID) {
    $typeID = (int) $typeID;
    $current++;

    $cursor = $collection->find(
        ['typeID' => $typeID],
        [
            'projection' => ['killID' => 1, '_id' => 0],
            'sort' => ['killID' => -1],
            'skip' => $keepPerItem - 1,
            'limit' => 1
        ]
    );
    $cutoffRows = iterator_to_array($cursor);
    $cutoff = current($cutoffRows);
    if ($cutoff == null || !isset($cutoff['killID'])) {
        continue;
    }

    $cutoffKillID = (int) $cutoff['killID'];
    $result = $collection->deleteMany([
        'typeID' => $typeID,
        'killID' => ['$lt' => $cutoffKillID]
    ]);
    $removed = $result->getDeletedCount();
    $totalRemoved += $removed;

    if ($removed > 0) {
        Util::out("($current/$totalTypes) itemmails cleanup typeID $typeID removed $removed rows older than killID $cutoffKillID");
    }
}

Util::out("itemmails cleanup removed $totalRemoved rows, keeping last $keepPerItem killmails for each item");
