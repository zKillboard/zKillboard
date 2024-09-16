<?php

require_once "../init.php";

$raw = @file_get_contents($eveKillLatest);
if ($raw == "") exit(); // eve-kill went down, try again later
$json = json_decode($raw, true);

foreach (array_keys($json) as $id) {
    $hash = $json[$id];

    $row = $mdb->findDoc("crestmails", ['killID' => $id, 'hash' => $hash]);
    if ($row == null) {
        Util::out("Adding from evekill: $id $hash");
        $mdb->insert("crestmails", ['killID' => $id, 'hash' => $hash, 'processed' => true]);
    }
}
