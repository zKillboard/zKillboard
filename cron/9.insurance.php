<?php

require_once '../init.php';

$date = (int) date('Ymd', time() - 7200);
if ($redis->get("zkb:insuranceFetched:$date") == true) {
    exit();
}

if ($redis->get("tqStatus") != "ONLINE") exit();

$json = CrestTools::curlFetch("$crestServer/insuranceprices/");
$insurance = json_decode($json, true);
$items = isset($insurance['items']) ? $insurance['items'] : [];
foreach ($items as $item) {
    $typeID = $item['type']['id'];
    $insert = ['typeID' => $typeID, 'date' => $date];
    foreach ($item['insurance'] as $i) {
        $insert[$i['level']] = ['cost' => round($i['cost']), 'payout' => round($i['payout'])];
    }
    $mdb->insertUpdate('insurance', ['typeID' => $typeID, 'date' => $date], $insert);
}
$redis->setex("zkb:insuranceFetched:$date", 86400, true);
