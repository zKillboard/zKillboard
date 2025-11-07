<?php

require_once '../init.php';

if (date('i') % 5 != 0) exit();

Status::check('esi');
$count = 0;
$crest = $mdb->find('crestmails', [], ['$natural' => -1]);
foreach ($crest as $row) {
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
