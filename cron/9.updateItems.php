<?php

require_once '../init.php';

if ($redis->get('tq:itemsPopulated') != true) {
    Util::out('Waiting for items to be populated...');
    exit();
}

$assign = ['capacity', 'name', 'portionSize', 'mass', 'volume', 'description', 'radius', 'published'];
$attrs = ['lowSlots', 'medSlots', 'hiSlots', 'rigSlots', 'techLevel', 'shieldCapacity', 'armorHP', 'hp'];

$hour24 = $mdb->now(-86400);
$rows = $mdb->find('information', ['type' => 'typeID', 'lastApiUpdate' => ['$lt' => $hour24]]);
foreach ($rows as $row) {
    $typeID = (int) $row['id'];
    $crest = CrestTools::getJSON("$crestServer/inventory/types/$typeID/");

    foreach ($assign as $key) {
        if (isset($crest[$key])) {
            $row[$key] = $crest[$key];
        }
    }

    // Dogma
    if (isset($crest['dogma']['attributes'])) {
        foreach ($crest['dogma']['attributes'] as $attribute) {
            $name = $attribute['attribute']['name'];
            $value = $attribute['value'];
            $row[$name] = $value;
        }
    }

    $row['lastApiUpdate'] = $mdb->now();
    $mdb->save('information', $row);
}
