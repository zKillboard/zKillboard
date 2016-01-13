<?php

require_once "../init.php";

$date = date('Ymd', time() - 7200);
$yesterday = date('Y-m-d', time() - 7200 - 86400);
$key = "tq:pricesChecked:$date";
if ($redis->get($key) == true) exit();

$crestPrices = CrestTools::getJson("https://public-crest.eveonline.com/market/prices/");
if (!isset($crestPrices['items'])) exit();

foreach ($crestPrices['items'] as $item) {
	$typeID = $item['type']['id'];
	$price = Price::getItemPrice($typeID, $date, true);

	$marketHistory = $mdb->findDoc("prices", ['typeID' => $typeID]);
	if ($marketHistory === null)
	{
		$marketHistory = ['typeID' => $typeID];
		$mdb->save("prices", $marketHistory);
	}

	if (!isset($marketHistory[$yesterday]) && isset($item['averagePrice']))
	{
		$avgPrice = $item['averagePrice'];
		$mdb->set("prices", ['typeID' => $typeID], [$yesterday => $avgPrice]);
	}

}

$redis->set($key, true);
