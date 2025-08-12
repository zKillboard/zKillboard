<?php 

require_once "../init.php";

$minute = date("Hi");

if ($mdb->getCollection("killmails")->count(['reset' => true]) > 0) {
    $redis->set("zkb:statsStop", "true");
    sleep(60);

    $cursor = $mdb->getCollection("killmails")->find(['reset' => true]);
    foreach ($cursor as $row) {
        if (date("Hi") != $minute) break;
        $killID = $row['killID'];
        Util::out("Resetting $killID");
        Killmail::deleteKillmail($killID);
    }
    $redis->del("zkb:statsStop");
}
