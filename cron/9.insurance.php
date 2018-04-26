<?php

require_once '../init.php';

$date = (int) date('Ymd', time() - 7200);
if ($redis->get("zkb:insuranceFetched:$date") == true) {
    exit();
}

Status::check('esi');

$guzzler = new Guzzler();
$guzzler->call("$esiServer/v1/insurance/prices/", "success", "fail", ['date' => $date]);
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
