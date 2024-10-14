<?php

require_once "../init.php";

$raw = @file_get_contents($eveKillLatest);
if ($raw == "") exit(); // eve-kill went down, try again later
$json = json_decode($raw, true);

$count = 0;
foreach (array_keys($json) as $id) {
    $hash = $json[$id];

    $row = $mdb->findDoc("crestmails", ['killID' => $id, 'hash' => $hash]);
    if ($row == null) {
        $count++;
        $mdb->insert("crestmails", ['killID' => $id, 'hash' => $hash, 'processed' => true]);
    }
}
if ($count > 0) Util::out("Adding $count kill(s) from evekill");
