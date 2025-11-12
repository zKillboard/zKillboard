<?php

require_once '../init.php';

if (date('i') != 11) exit();

Status::check('esi');
$count = 0;

// Get cursor instead of loading all documents into memory
$collection = $mdb->getCollection('crestmails');
$cursor = $collection->find([], [
    'sort' => ['$natural' => -1],
    'limit' => 25000,
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
        continue;
    }
    $count = 0;

    $esi = $mdb->findDoc('esimails', ['killmail_id' => $killID]);
    if ($esi !== null) {
        $mdb->set('crestmails', ['killID' => $killID], ['processed' => 'fetched']);
    } else {
        $mdb->set('crestmails', ['killID' => $killID], ['processed' => false]);
    }
    Util::out("Resetting $killID");
}
