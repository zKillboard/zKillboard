<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;

$validTypes = [
  'allianceID',
  'characterID',
  'corporationID',
  'factionID',
  'groupID',
   'typeID', //shipTypeID 
];

$cursor = $mdb->getCollection("information")->find();

while ($cursor->hasNext()) {
    $row = $cursor->next();
    $type = $row['type'];
    $id = $row['id'];

    if (!in_array($type, $validTypes)) continue;
    if ($type == "typeID") {
        $type = "shipTypeID";
        if (@$row['categoryID'] != 6) continue;
    }

    $t = new Timer();
    if ($mdb->findDoc("killmails", ['involved.' . $type => $id]) != null) {
        if ($mdb->count("statistics", ['type' => $type, 'id' => $id]) == 0) {
            $mdb->insert("statistics", ['type' => $type, 'id' => $id, 'reset' => true]);
            Util::out("Added " . $row['name']);
        }
    }
    if ($t->stop() > 1000) Util::out("$type $id " . $t->stop());
}
