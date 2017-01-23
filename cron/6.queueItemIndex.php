<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$itemQueue = new RedisQueue('queueItemIndex');

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = (int) $itemQueue->pop();
    if ($killID > 0) {
        Util::out("Adding items for $killID");
        $killmail = $mdb->findDoc("rawmails", ['killID' => $killID]);
        updateItems($killID, $killmail['victim']['items']);
    } else exit();
}

function updateItems($killID, $items) {
    global $mdb;

    foreach ($items as $item) {
        $typeID = (int) $item['itemType']['id'];
        if (!$mdb->exists('itemmails', ['killID' => $killID, 'typeID' => $typeID])) {
            $mdb->insert('itemmails', ['killID' => $killID, 'typeID' => $typeID]);
        }
        if (isset($items['items'])) {
            updateItems($killID, $items['items']);
        }
    }
}
