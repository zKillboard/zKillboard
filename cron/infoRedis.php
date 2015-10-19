<?php

require_once '../init.php';

$i = date('i');
if ($i != 15) {
    exit();
}

$information = $mdb->getCollection('information');
$types = $mdb->getCollection('information')->distinct('type');

foreach ($types as $type) {
    if ($type == 'warID') {
        continue;
    }
    $typeRows = $information->find(['type' => $type]);
    Util::out("Adding $type to redis");
    foreach ($typeRows as $row) {
        $id = $row['id'];
        $key = "tq:$type:$id";
	$redis->del($key);
        $redis->hMSet($key, $row);
        $redis->expire($key, 9600);
    }
}
