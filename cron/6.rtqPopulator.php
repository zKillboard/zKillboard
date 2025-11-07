<?php
exit();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";


populate('allianceID');
populate('corporationID');
populate('characterID');

function populate($type) {
    global $mdb;

    $queue = new RedisTimeQueue('zkb:' . $type, 9600);
    $rows = $mdb->getCollection("information")->find(['type' => $type]);
    foreach ($rows as $row) {
        if ($row['id'] <= 1) continue;
        //if (@$row['name'] == "") echo "$type " . $row['id'] . " empty name\n";
        if (@$row['name'] == "") $queue->remove($row['id']); // Go for immediate update
        $queue->add($row['id']);
    }
}
