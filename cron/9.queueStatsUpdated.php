<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$queueStatsUpdated = new RedisQueue('queueStatsUpdated');
$minute = date("Hi");

$all = false;
while ($minute == date("Hi")) {
    if ($redis->scard("queueStatsUpdated") > 0) {
        $s = unserialize($redis->spop("queueStatsUpdated"));
        publish($redis, $s['type'], $s['id']);
        $all = true;
    } else sleep(5);
}
if ($all) publish($redis, "label", "all");

function publish($redis, $type, $id) {
    //if ($redis->get("zkb:overview:$type:$id") != "true") return;
    $msg = json_encode(['action' => 'statsbox', 'type' => $type, 'id' => $id], JSON_UNESCAPED_SLASHES);
    $typed = str_replace("ID", "", $type);
    $redis->publish("stats:$typed:$id", $msg);
    usleep(5000);
}
