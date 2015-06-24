<?php

require_once '../init.php';

$killID = $mdb->findField('killmails', 'killID', [], ['killID' => -1]);
for ($i = $killID; $i >= ($killID - 5000); --$i) {
    $crestmail = $mdb->findDoc('crestmails', ['killID' => $i]);
    if ($crestmail == null) {
        continue;
    }
    if (@$crestmail['processed'] === true) {
        continue;
    }
    if (@$crestmail['processed'] === false) {
        continue;
    }

    $hash = $crestmail['hash'];
    //echo "Reseting http://public-crest.eveonline.com/killmails/$i/$hash/\n";
    $mdb->set('crestmails', ['killID' => $i], ['processed' => false]);
    sleep(1);
}
