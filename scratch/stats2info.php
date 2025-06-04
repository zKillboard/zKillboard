<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;

$count = 0;
$cursor = $mdb->getCollection("statistics")->find()->sort(['_id' => -1]);
while ($cursor->hasNext()) {
    $count++;
    $row = $cursor->next();
    $type = $row['type'];
    $id = $row['id'];
    if (!in_array($type, ['characterID', 'corporationID', 'allianceID'])) continue;
    $infoRow = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
    if ($infoRow == null) {
        Util::out("$type $id");
        $defaultName = "$type $id";
        $row = ['type' => $type, 'id' => (int) $id];
        $mdb->insertUpdate('information', $row, ['name' => $defaultName]);
        $rtq = new RedisTimeQueue("zkb:$type", 86400);
        $rtq->add($id);
        $count = 0;
    }
    if ($count > 100000) break;
}
