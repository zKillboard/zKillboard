<?php

require_once '../init.php';

$count = 0;
$crest = $mdb->getCollection('crestmails')->find(['errorCode' => 500]);
foreach ($crest as $row) {
    $killID = $row['killID'];
    $mdb->getCollection('crestmails')->update(['killID' => $killID], ['$unset' => ['errorCode' => 1, 'npcOnly' => 1]]);

    $mdb->set('crestmails', ['killID' => $killID], ['processed' => false]);
    ++$count;
    sleep(1);
}
echo "Reset " . number_format($count, 0) . " killmails\n";
