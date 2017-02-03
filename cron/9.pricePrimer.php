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

$crestPrices = CrestTools::getJson("$crestServer/market/prices/");
if (!isset($crestPrices['items'])) {
    exit();
}

foreach ($crestPrices['items'] as $item) {
    $typeID = $item['type']['id'];
    $price = Price::getItemPrice($typeID, $date, true);

    $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
    if ($marketHistory === null) {
        $marketHistory = ['typeID' => $typeID];
        $mdb->save('prices', $marketHistory);
    }

    if (!isset($marketHistory[$yesterday]) && isset($item['averagePrice'])) {
        $avgPrice = $item['averagePrice'];
        $mdb->set('prices', ['typeID' => $typeID], [$yesterday => $avgPrice]);
    }
}

$set = $mdb->find("information", ['type' => 'typeID']);
foreach ($set as $item) {
    if (@$item['published'] == false) continue;
    if (@$item['marketable'] == false) continue;
    $typeID = $item['id'];
    $price = Price::getItemPrice($typeID, $date, true);
}

$redis->setex($key, 86400, true);
