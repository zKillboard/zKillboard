<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$itemQueue = new RedisQueue('queueItemIndex');

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = (int) $itemQueue->pop();
    if ($killID > 0) {
        $killmail = $mdb->findDoc("rawmails", ['killID' => $killID]);
        updateItems($killID, $killmail['victim']['items']);
    } else exit();
}

function updateItems($killID, $items, $inContainer = false) {
    global $mdb;

    foreach ($items as $item) {
        $typeID = (int) $item['itemType']['id'];
        if ($typeID == 0) continue;

        $name = Info::getInfoField('typeID', $typeID, 'name');
        $isBlueprint = strpos($name, ' Blueprint') !== false;

        if ($isBlueprint && $inContainer == true) continue;
        if ($isBlueprint && $item['singleton'] != 0) continue;
        if ($isBlueprint && $killID < 21103361) continue;

        if (!$mdb->exists('itemmails', ['killID' => $killID, 'typeID' => $typeID])) {
            $mdb->insert('itemmails', ['killID' => $killID, 'typeID' => $typeID]);
        }
        if (isset($items['items'])) {
            updateItems($killID, $items['items'], true);
        }
    }
}
