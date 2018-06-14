<?php

require_once '../init.php';

global $fetchWars;

if ($fetchWars == null || $fetchWars == false) {
    exit();
}
if ($redis->get("zkb:420prone") == "true") exit();

$key = 'tqFetchWars';
if ($redis->get($key) == true) {
    exit();
}

$page = 1;

$guzzler = new Guzzler();
$guzzler->call("$esiServer/v1/wars/", "success", "fail");
$guzzler->finish();

$redis->setex($key, 3600, true);

function success(&$guzzler, &$params, $content)
{
    global $mdb, $esiServer;

    $maxWarID = 9999999999;
    $warsAdded = false;
    $wars = $content == "" ? [] : json_decode($content, true);
    foreach ($wars as $warID) {
        if (!$mdb->exists('information', ['type' => 'warID', 'id' => (int) $warID])) {
            $mdb->save('information', ['type' => 'warID', 'id' => $warID, 'lastApiUpdate' => new MongoDate(2)]);
            $warsAdded = true;
        }
        $maxWarID = min($maxWarID, $warID);
    }
    if ($warsAdded && sizeof($wars) > 0) {
        $guzzler->call("$esiServer/v1/wars/?max_war_id=$maxWarID", "success", "fail", $params);
    }
}

function fail(&$guzzler, &$params, $content)
{

}
