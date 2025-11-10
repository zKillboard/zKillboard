<?php

require_once '../init.php';

global $fetchWars;

if ($redis->get("zkb:noapi") == "true") exit();
if ($fetchWars !== true) exit();

if ($redis->get("zkb:420prone") == "true") exit();

$key = 'tqFetchWars';
if ($redis->get($key) == true) {
    exit();
}

$page = 1;

$guzzler = new Guzzler();
$guzzler->call("$esiServer/wars/", "success", "fail");
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
        $guzzler->call("$esiServer/wars/?max_war_id=$maxWarID", "success", "fail", $params);
    }
    $guzzler->sleep(1);
}

function fail(&$guzzler, &$params, $content)
{
    exit("error fetching wars\n");
}
