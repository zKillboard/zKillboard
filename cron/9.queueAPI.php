<?php

$mt = 8; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once "../init.php";

if (isset($cronForks[basename(__FILE__)]) && $mt > $cronForks[basename(__FILE__)]) exit();

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:reinforced") == true) break;

    $queueAPI = $queueAPI ?? new MongoQueue($mdb, 'queueAPI', true);
    $key = $queueAPI->pop();
    if ($key == null) { 
    	usleep(100000);
    	continue;
    }

    $paramsKey = "zkb:api:params:$key";
    $redis->multi();
    $redis->get($paramsKey);
    $redis->del($paramsKey);
    $serial = $redis->exec()[0];
    if ($serial === false || $serial === null) {
        continue;
    }
    $parameters = unserialize($serial);

    try {
        $result = Feed::getKills($parameters);
    } catch (Exception $e) {
        $result = ['error' => $e->getMessage()];
    }

    $redis->setex("zkb:api:result:$key", 60, serialize($result));
    $redis->del("zkb:api:status:$key");
}
