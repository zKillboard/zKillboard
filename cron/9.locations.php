<?php

require_once '../init.php';

if ($kvc->get("zkb:locationsLoaded") == "true") exit();

$raw = file_get_contents("https://sde.zzeve.com/invNames.json");
$invNames = json_decode($raw, true);
$names = [];
foreach ($invNames as $row) {
    $names[$row['itemID']] = $row['itemName'];
}
$invNames = null;

$bulkSize = 10000;
$ops = [];
$infoCollection = $mdb->getCollection('information');

$map = $mdb->getCollection('locations_calced')->find();
foreach ($map as $row) {
    $locationID = @$row['id'];
    if (!is_numeric($locationID)) continue;
    $locationID = (int) $locationID;

    $name = (string) (@$row['name'] ?? '');
    if ($name == '') $name = @$names[$locationID];
    if ($name == '') $name = "Location " . $locationID;

    $typeID = null;
    $sourceCollection = @$row['sourceCollection'];
    if (is_string($sourceCollection) && $sourceCollection != '') {
        $sourceDoc = $mdb->findDoc($sourceCollection, ['$or' => [['key' => $row['id']], ['_key' => $row['id']]]]);
        if ($sourceDoc != null && isset($sourceDoc['typeID']) && is_numeric($sourceDoc['typeID'])) {
            $typeID = (int) $sourceDoc['typeID'];
        }
    }

echo "$locationID $name\n";
    $update = ['name' => $name];
    if ($typeID !== null) $update['typeID'] = $typeID;
    $ops[] = [
        'updateOne' => [
            ['type' => 'locationID', 'id' => $locationID],
            ['$set' => $update],
            ['upsert' => true],
        ],
    ];

    if (count($ops) >= $bulkSize) {
        $infoCollection->bulkWrite($ops, ['ordered' => false]);
        $ops = [];
    }
}

if (!empty($ops)) {
    $infoCollection->bulkWrite($ops, ['ordered' => false]);
}

$kvc->set("zkb:locationsLoaded", "true");

