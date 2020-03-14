<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if (date('i') % 5 != 0) exit();

populate('allianceID');
populate('corporationID');
populate('characterID');

function populate($type) {
    global $mdb;

    $queue = new RedisTimeQueue('zkb:' . $type, 9600);
    $rows = $mdb->find('information', ['type' => $type]);
    foreach ($rows as $row) {
        if ($row['id'] == 0) continue;
        $queue->add($row['id']);
    }
}
