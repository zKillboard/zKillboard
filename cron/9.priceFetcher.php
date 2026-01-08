<?php

require_once '../init.php';

exit();
global $primePrices;

if ($kvc->get("zkb:noapi") == "true") exit();
if ($primePrices != true) exit();

$date = date('Ymd', time() - 7200);
$key = "tq:pricesFetched:$date";
if ($redis->get($key) == "true") exit();
if ($redis->get("zkb:420prone") == "true") exit();

$guzzler = new Guzzler(10, 10);
$guzzler->call("$esiServer/markets/prices/", "success", "fail");
$guzzler->finish();

$redis->setex($key, 86400, "true");

function success($guzzler, $params, $content)
{
    global $mdb, $redis;

    if ($content == "") return;

    $date = date('Y-m-d');
    $json = json_decode($content, true);
    foreach ($json as $segment) {
        $typeID = $segment['type_id'];
        $blueprint = Build::getBlueprint($redis, $typeID);
        $buildable = ($blueprint != null && $blueprint['reqs'] != null);
        $adjPrice = (double) @$segment['adjusted_price'];
        $avgPrice = (double) @$segment['average_price'];
        if ($adjPrice > 0 && $avgPrice > 0) $price = min($adjPrice, $avgPrice);
        else $price = max($adjPrice, $avgPrice);
        if ($price < 0.01) continue;
        $row = $mdb->findDoc("prices", ['typeID' => $typeID]);
        if (isset($row[$date])) continue;
        if (!isset($row["typeID"])) $row["typeID"] = $typeID;
        $row[$date] = $price;
        $mdb->save("prices", $row);
    }
}

function fail($guzzler, $params, $error)
{
    Util::out("Failed to fetch prices...");
    exit();
}
