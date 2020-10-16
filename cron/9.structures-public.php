<?php

require_once "../init.php";

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:reinforced") == true) exit();

if ($redis->get("zkb:structure-fetch") == "true") exit();

$raw = file_get_contents("https://esi.evetech.net/latest/universe/structures/?datasource=tranquility");
$redis->setex("structures", 3600, $raw);
$json = json_decode($raw, true);

foreach ($json as $row) {
    $sid = (int) $row;
    $mdb->remove("structures", ['structure_id' => $sid, 'public' => false]);
    if ($mdb->count("structures", ['structure_id' => $sid]) == 0) {
        $mdb->insert("structures", ['structure_id' => $sid, 'public' => true, 'lastChecked' => 0, 'hasMatch' => false]);
    }
}

$redis->setex("zkb:structure-fetch", 3601, "true");
