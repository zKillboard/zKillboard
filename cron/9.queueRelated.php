<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$minute = date('Hi');
while ($minute == date('Hi')) {
    usleep(100000);
    if ($redis->get("zkb:reinforced") == true) break;

    $key = $redis->spop("queueRelatedSet");
    if ($key == null) continue;
    $serial = $redis->get("$key:params");
    if ($serial == null) continue;

    $parameters = unserialize($serial);
    $current = $redis->get($parameters['key']);
    if ($redis->get($parameters['key']) !== false) continue;

    if ($redis->scard("queueRelatedSet") > 10 && (sizeof($parameters['options']['A']) > 0 || sizeof($parameters['options']['B']) > 0)) { Util::out("skipping related"); continue; }
    if ($redis->scard("queueRelatedSet") > 20) continue;

    if ($redis->get($parameters['key']) != null) continue;
    $kills = Kills::getKills($parameters);
    $summary = Related::buildSummary($kills, $parameters['options']);

    $serial = serialize($summary);
    $redis->setex($parameters['key'], 900, $serial);
    $redis->setex('backup:'.$parameters['key'], 3600, $serial);
    $redis->srem('queueRelatedSet', $key);
    $redis->del("$key:params");
}
