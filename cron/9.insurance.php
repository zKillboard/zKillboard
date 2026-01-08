<?php

require_once '../init.php';

if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

if ($kvc->get("zkb:noapi") == "true") exit();
$date = (int) date('Ymd', time() - 7200);
if ($redis->get("zkb:insuranceFetched:$date") == true) {
    exit();
}
if ($redis->get("zkb:420prone") == "true") exit();

$guzzler = new Guzzler();
$guzzler->call("$esiServer/insurance/prices/", "success", "fail", ['date' => $date]);
$guzzler->finish();

$redis->setex("zkb:insuranceFetched:$date", 86400, true);

function success($guzzler, $params, $content)
{
    global $mdb;

    if ($content == "") return;

    $json = json_decode($content, true);
    foreach ($json as $row) {
        $typeID = $row['type_id'];
        $insert = ['typeID' => $typeID, 'date' => $params['date']];
        foreach ($row['levels'] as $i) {
            $insert[$i['name']] = ['cost' => round($i['cost']), 'payout' => round($i['payout'])];
        }
        $mdb->insertUpdate('insurance', ['typeID' => $typeID, 'date' => $params['date']], $insert);
    }
}

function fail($guzzler, $params, $ex)
{

}
