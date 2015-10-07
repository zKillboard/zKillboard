<?php

require_once '../init.php';

if (date('i') % 5  != 0) exit();

$apis = $mdb->getCollection('apis');
$tqApis = new RedisTimeQueue('tqApis', 9600);

$allApis = $mdb->find('apis');
foreach ($allApis as $api) {
	$errorCode = (int) @$api['errorCode'];
	if (in_array($errorCode, [106, 203, 220, 222, 404, 522])) continue;
	$tqApis->add($api['_id']);
}
