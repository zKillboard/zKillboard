<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queueRelated = new RedisQueue('queueRelated');
$timer = new Timer();

$minutely = date('Hi');
while ($minutely == date('Hi')) {
    $serial = $queueRelated->pop();
    if ($serial == null) {
        sleep(1);
        continue;
    }
    $parameters = unserialize($serial);
    $current = $redis->get($parameters['key']);
    if ($redis->get($parameters['key']) !== false) {
        continue;
    }
    $kills = Kills::getKills($parameters);
    $summary = Related::buildSummary($kills, $parameters['options']);

    $serial = serialize($summary);
    $redis->setex($parameters['key'], 3600, $serial);
    $redis->setex('backup:'.$parameters['key'], 7200, $serial);
}
