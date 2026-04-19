<?php

require_once "../init.php";

if (date("i") % 5 != 0) exit();

$raw = @file_get_contents("https://api.eve-kill.com/history/latest");
if ($raw == "") exit(); // eve-kill went down, try again later
$json = json_decode($raw, true);

$data = $json['data'];
$count = 0;
foreach (array_keys($data) as $id) {
    $hash = $data[$id];
    $id = (int) $id;
    if ($id <= 0) continue;
    if (strlen($hash) != 40) continue;

    $row = $mdb->findDoc("crestmails", ['killID' => $id, 'hash' => $hash]);
    if ($row == null) {
        $count++;
        $mdb->insert("crestmails", ['killID' => $id, 'hash' => $hash, 'processed' => false, 'source' => 'eve-kill', 'delay' => 0]);
    }
}
if ($count > 0) Util::out("Adding $count kill(s) from evekill");
