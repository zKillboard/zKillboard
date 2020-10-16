<?php

require_once "../init.php";

if ($redis->get("zkb:noapi") == "true") exit();

if (date('Hi') < 1200) exit();
$rkey = "zkb:factions:" . date('Ymd');
if ($redis->get($rkey) == "true") exit();

$raw = file_get_contents("https://esi.evetech.net/latest/universe/factions/");
$json = json_decode($raw, true);

foreach ($json as $faction) {
    $mdb->insertUpdate("information", ['type' => 'factionID', 'id' => (int) $faction['faction_id']], ['name' => $faction['name']]);
}

$redis->setex($rkey, 86400, "true");
