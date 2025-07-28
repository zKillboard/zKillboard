<?php

require_once "../init.php";

$minute = date("Hi");

$delays = [
    0 => 0,
    1 => 3600, // 1 hour
    2 => 10800, // 3 hours
    3 => 28800, // 8 hours
    4 => 86400, // 24 hours
    5 => 259200 // 72 hours (3 days)
];

while ($minute == date("Hi")) {
    $now = time();
    $mdb->set('crestmails', ['processed' => 'delayed', 'delay' => ['$exists' => false]], ['delay' => 0], true);
    $mdb->set('crestmails', ['processed' => 'delayed', 'delay' => ['$exists' => true], 'epoch' => ['$exists' => false]], ['epoch' => $now], true);
    foreach ($delays as $delay=>$delta) {
        $offset = $now - $delta;
        $delayed = $mdb->find('crestmails', ['processed' => 'delayed', 'delay' => $delay, 'epoch' => ['$lte' => $offset]]);
        foreach ($delayed as $crestmail) {
            $killID = $crestmail['killID'];
            $mdb->set('crestmails', $crestmail, ['processed' => 'fetched']);
            $redis->zadd("tobeparsed", $killID, $killID);
        }    
    }
    sleep(1);
}
