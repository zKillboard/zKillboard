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

$map = $mdb->getCollection('locations')->find();
foreach ($map as $system) {
    foreach ($system['locations'] ?? [] as $row) {
        $name = $row['itemname'];
        if ($name == '') $name = @$names[$row['itemid']];
        if ($name == '') $name = "Location " . $row['itemid'];
        $mdb->insertUpdate('information', ['type' => 'locationID', 'id' => (int) $row['itemid']], ['name' => $name, 'typeID' => $row['typeid']]);
    }
}

$kvc->set("zkb:locationsLoaded", "true");
