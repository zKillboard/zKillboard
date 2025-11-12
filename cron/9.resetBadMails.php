<?php

require_once '../init.php';

if (date('i') != 11) exit();

Status::check('esi');
$count = 0;

// Get cursor instead of loading all documents into memory
$collection = $mdb->getCollection('crestmails');
$cursor = $collection->find([], [
    'sort' => ['$natural' => -1],
    'noCursorTimeout' => true
]);

foreach ($cursor as $row) {
    $killID = $row['killID'];
    if (isset($row['npcOnly'])) {
        continue;
    }
    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    ++$count;
    if ($killmail != null) {
        if ($count > 10000) {
            exit();
        }
        continue;
    }
    $count = 0;

    $mdb->set('crestmails', ['killID' => $killID], ['processed' => false]);
    sleep(1);
}

