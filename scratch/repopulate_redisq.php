<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$queueRedisQ = new RedisQueue('queueRedisQ');

$cursor = $mdb->getCollection("killmails")->find()->sort(['_id' => -1])->limit(500);
while ($cursor->hasNext()) {
    $next = $cursor->next();
    $queueRedisQ->push($next['killID']);
    echo $next['killID'] . "\n";
}
