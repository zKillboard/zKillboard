<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once "../init.php";

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:reinforced") == true) break;

    $key = $redis->spop("queueAPI");
    if ($key == null) { 
    	usleep(100000);
    	continue;
    }

    $serial = $redis->get("zkb:api:params:$key");
    $parameters = unserialize($serial);

    try {
        $result = Feed::getKills($parameters);
    } catch (Exception $e) {
        $result = ['error' => $e->getMessage()];
    }

    $redis->setex("zkb:api:result:$key", 60, serialize($result));
    $redis->del("zkb:api:status:$key");
}
