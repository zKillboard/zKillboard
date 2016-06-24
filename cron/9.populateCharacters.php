<?php

require_once '../init.php';

$redisKey = "zkb:populateCharacters";
if ($redis->get($redisKey) != false) exit();

$information = $mdb->getCollection('information');
$queueCharacters = new RedisTimeQueue('tqCharacters', 86400);

$characters = $information->find(['type' => 'characterID']);
foreach ($characters as $char) {
	$queueCharacters->add($char['id']);
}

$redis->setex($redisKey, 3600, true);
