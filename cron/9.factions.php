<?php

require_once "../init.php";

if ($kvc->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

if (date('Hi') < 1200) exit();
$rkey = "zkb:factions:" . date('Ymd');
if ($kvc->get($rkey) == "true") exit();

$url = "$esiServer/universe/factions/";
$guzzler = new Guzzler();
$guzzler->call($url, "success", "fail", ['rkey' => $rkey]);
$guzzler->finish();

function success(&$guzzler, &$params, &$content)
{
    global $mdb, $kvc;

    $json = json_decode($content, true);
    if (!is_array($json)) return;

    foreach ($json as $faction) {
        $mdb->insertUpdate("information", ['type' => 'factionID', 'id' => (int) $faction['faction_id']], ['name' => $faction['name']]);
    }

    $kvc->setex($params['rkey'], 86400, "true");
}

function fail(&$guzzler, &$params, &$ex)
{
    Util::out("Faction fetch failed with http code " . $ex->getCode());
}
