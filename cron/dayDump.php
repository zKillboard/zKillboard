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
    $hash = trim($row['zkb']['hash']);
    if ($killID <= 0 || $hash == "") continue;

    $redis->hset("zkb:day:$date", $killID, $hash);
    $redis->sadd("zkb:days", $date);
}

// Refresh the history endpoint every few days
$redis->setex($key, (86400 * 4), "true");
