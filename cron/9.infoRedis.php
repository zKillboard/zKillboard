<?php

require_once '../init.php';

$key = date('YmdH');
if ($redis->get($key) == true) exit();

$information = $mdb->getCollection('information');
$types = $mdb->getCollection('information')->distinct('type');

foreach ($types as $type) {
	if ($type == 'warID') continue;

	$typeRows = $information->find(['type' => $type]);
	if ($debug) Util::out("Adding $type to redis");
	foreach ($typeRows as $row) {
		unset($row['_id']);
		$id = $row['id'];
		$key = "tq:$type:$id";
		$multi = $redis->multi();
		$multi->del($key);
		$multi->hMSet($key, $row);
		$multi->exec();
	}
}

$redis->setex($key, 3600, true);
