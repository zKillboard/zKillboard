<?php

require_once '../init.php';

if ($redis->get("zkb:locationsLoaded") == "true") exit();

$raw = file_get_contents("http://sde.zzeve.com/invNames.json");
$invNames = json_decode($raw, true);
$names = [];
foreach ($invNames as $row) {
    $names[$row['itemID']] = $row['itemName'];
}
$invNames = null;

$map = $mdb->getCollection('locations')->find();
foreach ($map as $system) {
    foreach ($system['locations'] as $row) {
        $name = $row['itemname'];
        if ($name == '') $name = $names[$row['itemid']];
        echo $row['itemid']." $name\n";
        $mdb->insertUpdate('information', ['type' => 'locationID', 'id' => (int) $row['itemid']], ['name' => $name]);
    }
}

$redis->set("zkb:locationsLoaded", "true");
