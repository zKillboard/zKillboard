<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$queueRelated = new RedisQueue('queueRelated');

$minute = date('Hi');
while ($minute == date('Hi')) {
    $serial = $queueRelated->pop();
    if ($serial == null) {
        sleep(1);
        continue;
    }
    if ($redis->get("zkb:reinforced") == true) {
        continue;
    }
    $parameters = unserialize($serial);
    $current = $redis->get($parameters['key']);
    if ($redis->get($parameters['key']) !== false) {
        continue;
    }
    $kills = Kills::getKills($parameters);
    if ($parameters['solarSystemID'] == 30000142) {
        $summary = [];
    } else  $summary = Related::buildSummary($kills, $parameters['options']);

    $serial = serialize($summary);
    $redis->setex($parameters['key'], 200, $serial);
    $redis->setex('backup:'.$parameters['key'], 3600, $serial);
}
