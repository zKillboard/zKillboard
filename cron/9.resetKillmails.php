<?php 

require_once "../init.php";

$minute = date("Hi");

$cursor = $mdb->getCollection("killmails")->find(['reset' => true]);
foreach ($cursor as $row) {
    if (date("Hi") != $minute) break;
    $killID = $row['killID'];
    Util::out("Resetting $killID");
    Killmail::deleteKillmail($killID);
}
