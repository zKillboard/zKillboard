<?php

require_once '../init.php';

// Run once an hour
$minute = (int) date('i');
//if ($minute != 0) exit();

$page = ceil($mdb->count('information', ['type' => 'warID']) / 2000);
if ($page == 0) {
    $page = 1;
}

$next = "http://public-crest.eveonline.com/wars/?page=$page";
do {
    $wars = CrestTools::getJSON($next);
    if ($wars == null) {
        exit();
    }
    $next = @$wars['next']['href'];
    foreach ($wars['items'] as $war) {
        $warID = (int) $war['id'];
//echo "$warID\n";
        if (!$mdb->exists('information', ['type' => 'warID', 'id' => $warID])) {
            $mdb->save('information', ['type' => 'warID', 'id' => $warID, 'lastCrestUpdate' => new MongoDate(2)]);
        }
    }
    sleep(5);
} while ($next != null);
