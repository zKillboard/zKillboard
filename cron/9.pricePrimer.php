<?php

require_once '../init.php';

global $primePrices;

if ($primePrices != true) {
    exit();
}

$date = date('Ymd', time() - 7200);
$yesterday = date('Y-m-d', time() - 7200 - 86400);
$key = "tq:pricesChecked:$date";
if ($redis->get($key) == true) {
    exit();
}

if ($redis->get("tqStatus") != "ONLINE") exit();
$crestPrices = CrestTools::getJson("$crestServer/market/prices/");
if (!isset($crestPrices['items'])) {
    exit();
}

foreach ($crestPrices['items'] as $item) {
    if ($redis->get("tqStatus") != "ONLINE") exit();
    $typeID = $item['type']['id'];
    $price = Price::getItemPrice($typeID, $date, true);

    $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
    if ($marketHistory === null) {
        $marketHistory = ['typeID' => $typeID];
        $mdb->save('prices', $marketHistory);
    }

    $price = isset($item['averagePrice']) ? $item['averagePrice'] : (isset($item['adjustedPrice']) ? $item['adjustedPrice'] : 0);
    if (!isset($marketHistory[$yesterday]) && $price > 0) {
        $mdb->set('prices', ['typeID' => $typeID], [$yesterday => $price]);
    }
}

$set = $mdb->find("information", ['type' => 'typeID'], ['id' => 1]);
foreach ($set as $item) {
    if (@$item['published'] == false) continue;
    //if (@$item['marketable'] == false) continue;
    $typeID = $item['id'];
    $price = Price::getItemPrice($typeID, $date, true);
}

$redis->setex($key, 86400, true);
