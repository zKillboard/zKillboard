<?php 

require_once "../init.php";

if ($mdb->getCollection("killmails")->count(['reset' => true]) > 0) {
    $redis->setex("zkb:statsStop", 120, "true");
    sleep(60);

    $cursor = $mdb->getCollection("killmails")->find(['reset' => true]);
    $minute = date("Hi");
    foreach ($cursor as $row) {
        if (date("Hi") != $minute) break;
        $redis->setex("zkb:statsStop", 120, "true");
        $killID = $row['killID'];
        Util::out("Resetting $killID");
        Killmail::deleteKillmail($killID);
    }
    $redis->del("zkb:statsStop");
}
