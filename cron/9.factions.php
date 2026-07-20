<?php

require_once "../init.php";

if ($kvc->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

if (date('Hi') < 1200) exit();
$rkey = "zkb:factions:" . date('Ymd');
if ($kvc->get($rkey) == "true") exit();

$url = "https://esi.evetech.net/universe/factions/";
$http_response_header = [];
$raw = file_get_contents($url);
Status::addEsiStatusFromHttpResponseHeaders($url, $http_response_header);
$json = json_decode($raw, true);

foreach ($json as $faction) {
    $mdb->insertUpdate("information", ['type' => 'factionID', 'id' => (int) $faction['faction_id']], ['name' => $faction['name']]);
}

$kvc->setex($rkey, 86400, "true");
