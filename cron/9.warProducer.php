<?php

require_once '../init.php';

global $fetchWars;

if ($fetchWars == null || $fetchWars == false) {
    exit();
}

$key = 'tqFetchWars';
if ($redis->get($key) == true) {
    exit();
}

$page = ceil($mdb->count('information', ['type' => 'warID']) / 2000);
if ($page == 0) {
    $page = 1;
}

$next = "$crestServer/wars/?page=$page";
do {
    Status::check('crest');
    $wars = CrestTools::getJSON($next);
    if ($wars == null) {
        exit();
    }
    $next = @$wars['next']['href'];
    foreach ($wars['items'] as $war) {
        $warID = (int) $war['id'];
        if (!$mdb->exists('information', ['type' => 'warID', 'id' => $warID])) {
            $mdb->save('information', ['type' => 'warID', 'id' => $warID, 'lastApiUpdate' => new MongoDate(2)]);
        }
    }
    sleep(5);
} while ($next != null);

$redis->setex($key, 3600, true);
