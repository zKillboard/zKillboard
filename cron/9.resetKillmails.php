<?php 

require_once "../init.php";

if (toBeFetchedCount($mdb) > 20) exit();

if ($mdb->findDoc("killmails", ['reset' => true]) == null) {
    $redis->del("zkb:padhashchecking");
    exit();
}

$r = $mdb->find("killmails", ['reset' => true], [], 5000);
foreach ($r as $row) {
    $redis->setex("zkb:padhashchecking", 3600, "true");
    $killID = $row['killID'];
    Log::log("Resetting $killID");
    Killmail::deleteKillmail($killID);
}

function toBeFetchedCount($mdb) {
    return $mdb->count("crestmails", ['processed' => false]);
}
