<?php

require_once "../init.php";

$key = "zkb:dayDump";
if ($redis->get($key) == "true") exit();

Util::out("Populating dayDumps");

$cursor = $mdb->getCollection("killmails")->find([], ['dttm' => 1, 'killID' => 1, 'zkb.hash' => 1, '_id' => 0])->sort(['killID' => -1]);
foreach ($cursor as $row) {
    $time = $row['dttm']->sec;
    $time = $time - ($time % 86400);
    $date = date('Ymd', $time);
    $killID = $row['killID'];
    if ($killID <= 0) continue;
    $hash = $row['zkb']['hash'];

    $redis->hset("zkb:day:$date", $killID, $hash);
    $redis->sadd("zkb:days", $date);
}

$redis->set($key, "true");
