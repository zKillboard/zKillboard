<?php

use cvweiss\redistools\RedisQueue;

require_once "../init.php";

$queueStatsUpdated = new RedisQueue('queueStatsUpdated');
$minute = date("Hi");

while ($minute == date("Hi")) {
    $all = false;
    if ($queueStatsUpdated->size() > 0) {
        $next = $queueStatsUpdated->pop();
        if (!is_array($next)) continue;
        $type = $next['type'];
        $id = $next['id'];

        $msg = json_encode(['action' => 'statsbox', 'type' => $type, 'id' => $id], JSON_UNESCAPED_SLASHES);
        $typed = str_replace("ID", "", $type);
        if ($redis->get("r2w:broadcasted:$typed:$id") != "true") continue;
        $redis->publish("stats:$typed:$id", $msg);
        usleep(10000);
    }
    else sleep(5);
}
