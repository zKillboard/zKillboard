<?php

require_once '../init.php';

$count = 0;
$crest = $mdb->getCollection('crestmails')->find()->sort(['killID' => -1]);
foreach ($crest as $row) {
    ++$count;
    if ($count > 50000) {
        exit();
    }
    if (@$row['npcOnly'] == true) {
        continue;
    }
    $killID = $row['killID'];
    if ($mdb->exists('killmails', ['killID' => $killID])) {
        continue;
    }
    $mdb->set('crestmails', ['killID' => $killID], ['processed' => false]);
    sleep(1);
}
