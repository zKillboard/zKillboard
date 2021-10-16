<?php

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

    $result = Feed::getKills($parameters);

    $redis->setex("zkb:api:result:$key", 3600, serialize($result));
    $redis->del("zkb:api:status:$key");
}