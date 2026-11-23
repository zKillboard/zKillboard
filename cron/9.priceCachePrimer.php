<?php

require_once '../init.php';
global $primePrices;

if ($primePrices != true) exit();
if ($kvc->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:420prone") == "true") exit();
if ($kvc->get("zkb:universeSdeVersion") == null) exit();

// init.php uses UTC; warm the current pricing day starting at noon.
if (date('Hi') < 1200) exit();
$key = "tq:priceCachePrimed:" . date('Ymd');
if ($kvc->get($key) == "true") exit();

$priceDate = date('Y-m-d') . ' 12:00';
$types = $mdb->getCollection('information')->find(['type' => 'typeID'], ['projection' => ['id' => 1, 'published' => 1, 'market_group_id' => 1]]);
$count = 0;
foreach ($types as $type) {
    $fetch = !empty($type['published']) && ($type['market_group_id'] ?? 0) > 0;
    Price::getItemPrice((int) $type['id'], $priceDate, $fetch, true);
    $count++;
}

$kvc->setex($key, 86400, "true");
Util::out("Primed calculated prices for $count types for $priceDate");
