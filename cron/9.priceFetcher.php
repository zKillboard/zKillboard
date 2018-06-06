<?php

require_once '../init.php';

global $primePrices;

if ($primePrices != true) exit();

$date = date('Ymd', time() - 7200);
$key = "tq:pricesFetched:$date";
if ($redis->get($key) == "true") exit();

$guzzler = new Guzzler(10, 10);
$guzzler->call("$esiServer/v1/markets/prices/", "success", "fail");
$guzzler->finish();

$redis->setex($key, 86400, "true");

function success($guzzler, $params, $content)
{
    global $mdb; 

    if ($content == "") return;

    $date = date('Y-m-d');
    $json = json_decode($content, true);
    foreach ($json as $segment) {
        $typeID = $segment['type_id'];
        $price = isset($segment['average_price']) ? $segment['average_price'] : $segment['adjusted_price'];
        $row = $mdb->findDoc("prices", ['typeID' => $typeID]);
        if (isset($row[$date])) continue;
        $row[$date] = $price;
        $mdb->save("prices", $row);
    }
}

function fail($guzzler, $params, $error)
{
    Log::log("Failed to fetch prices...");
    exit();
}
