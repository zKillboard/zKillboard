<?php

require_once '../init.php';

if (date('i') != 15) exit();

$information = $mdb->getCollection('information');
$types = $mdb->getCollection('information')->distinct('type');

foreach ($types as $type) {
	if ($type == 'warID') {
		continue;
	}
	$typeRows = $information->find(['type' => $type]);
	Util::out("Adding $type to redis");
	foreach ($typeRows as $row) {
		unset($row['_id']);
		$id = $row['id'];
		$key = "tq:$type:$id";
		$multi = $redis->multi();
		$multi->del($key);
		$multi->hMSet($key, $row);
		$multi->expire($key, 86400);
		$multi->exec();
	}
}
