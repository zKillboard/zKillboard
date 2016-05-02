<?php

require_once '../init.php';

$cacheKey = "infoRedis:" . date('YmdH');
if ($redis->get($cacheKey) == true) exit();

$information = $mdb->getCollection('information');
$types = $mdb->getCollection('information')->distinct('type');

foreach ($types as $type) {
	if ($type == 'warID') continue;

	$typeRows = $information->find(['type' => $type]);
	foreach ($typeRows as $row) {
		unset($row['_id']);
		$id = $row['id'];
		$key = "tqCache:$type:$id";
		$multi = $redis->multi();
		$multi->del($key);
		$multi->hMSet($key, $row);
		$multi->expire($key, 9600);
		$multi->exec();
	}
}

$redis->setex($cacheKey, 3600, true);
