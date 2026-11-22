<?php

require_once '../init.php';
global $primePrices;

if ($kvc->get("zkb:noapi") == "true") exit();
if ($primePrices != true) exit();
if ($redis->get("zkb:420prone") == "true") exit();

$date = date('Ymd', time() - 7200);
$yesterday = date('Y-m-d', time() - 7200 - 86400);
$key = "tq:pricesChecked:$date";

// Market region history endpoint is refreshed at 11:05 daily
// We'll wait a few minutes then start pulling
if ($kvc->get($key) == "true" || date('Hi') < 1115) exit();

// Universe import supplies market membership from SDE types.
if ($kvc->get("zkb:universeSdeVersion") == null) exit();
$guzzler = new Guzzler(1);
$today = date('Ymd', time());
$fetchedKey = "tq:pricesFetched:$today";
$types = $mdb->getCollection('information')->find(['type' => 'typeID', 'published' => true, 'market_group_id' => ['$gt' => 0]], ['projection' => ['id' => 1]]);
foreach ($types as $type) {
    $typeID = $type['id'];
    if ($redis->hget($fetchedKey, $typeID) == true) continue;
    $guzzler->call("$esiServer/markets/10000002/history/?type_id=$typeID", "typeHistorySuccess", "fail", ['typeID' => $typeID]);
}
$guzzler->finish();

$kvc->setex($key, 86400, "true");

function typeHistorySuccess($guzzler, $params, $content)
{
    global $mdb, $redis;

    $type = json_decode($content, true);
    $typeID = (int) $params['typeID'];
    $row = $mdb->findDoc("prices", ['typeID' => $typeID]);
    foreach ($type as $r) {
        $avgPrice = $r['average'];
        $date = $r['date'];
        $row[$date] = $avgPrice;
    }
    $row['typeID'] = $typeID;

    $mdb->save("prices", $row);

    $today = date('Ymd', time());
    $key = "tq:pricesFetched:$today";
    $redis->hSet($key, $typeID, true);
    $redis->expire($key, 86400);
}

function fail($guzzler, $params, $error)
{
    global $esiServer;

    $code = method_exists($error, 'getCode') ? (int) $error->getCode() : 0;
    if ($code === 404) return;
    $message = method_exists($error, 'getMessage') ? $error->getMessage() : (string) $error;
    Util::out("Fail " . $params['uri'] . " (" . $code . "): " . $message);
}

