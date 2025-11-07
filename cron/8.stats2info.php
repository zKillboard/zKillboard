<?php

require_once "../init.php";

if (date("i") != 0) exit();

$count = 0;
$cursor = $mdb->find("statistics", [], ['_id' => -1]);
foreach ($cursor as $row) {
    $count++;
    
    $type = $row['type'];
    $id = $row['id'];
    if (!in_array($type, ['characterID', 'corporationID', 'allianceID'])) continue;
    $infoRow = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
    if ($infoRow == null) {
        Util::out("$type $id");
        $defaultName = "$type $id";
        $row = ['type' => $type, 'id' => (int) $id];
        $mdb->insertUpdate('information', $row, ['name' => $defaultName]);
        $count = 0;
    }
    if ($count > 100000) break;
}
