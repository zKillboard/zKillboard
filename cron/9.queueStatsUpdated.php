<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$queueStatsUpdated = new RedisQueue('queueStatsUpdated');
$minute = date("Hi");

$all = false;
while ($minute == date("Hi")) {
    if ($queueStatsUpdated->size() > 0) {
        $all = true;
        $next = $queueStatsUpdated->pop();
        if (!is_array($next)) continue;
        $type = $next['type'];
        $id = $next['id'];

        publish($redis, $type, $id);
    }
    else sleep(5);
}
if ($all) publish($redis, "label", "all");

function publish($redis, $type, $id) {
    $msg = json_encode(['action' => 'statsbox', 'type' => $type, 'id' => $id], JSON_UNESCAPED_SLASHES);
    $typed = str_replace("ID", "", $type);
    if ($redis->get("r2w:broadcasted:$typed:$id") != "true") return;
    $redis->publish("stats:$typed:$id", $msg);
    usleep(10000);
}
