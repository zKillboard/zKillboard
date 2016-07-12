<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$queueRelated = new RedisQueue("queueRelated");
$timer = new Timer();

while ($timer->stop() < 65000)
{
    $serial = $queueRelated->pop();
    if ($serial == null) continue;
    $parameters = unserialize($serial);
    $current = $redis->get($parameters['key']);
    if ($redis->get($parameters['key']) !== false) continue;
    $kills = Kills::getKills($parameters);
    $summary = Related::buildSummary($kills, $parameters['options']);

    $serial = serialize($summary);
    $redis->setex($parameters['key'], 1500, $serial);
    $redis->setex("backup:" . $parameters['key'], 1600, $serial);
}
