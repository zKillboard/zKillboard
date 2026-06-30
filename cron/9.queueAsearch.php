<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once '../init.php';

$minute = date('Hi');
while ($minute == date('Hi')) {
    $key = null;
    try {
        usleep(100000);
        if ($redis->get("zkb:reinforced") == true) break;
        if ($redis->ping() != 1) connectRedis();

        $key = $redis->spop("queueAsearchSet");
        if ($key == null) { sleep(1); continue; }
        if ($redis->get("$key:result") !== false) continue;

        $serial = $redis->get("$key:params");
        if ($serial == null) continue;

        if ($redis->get($key) !== "PROCESSING") {
            $redis->del("$key:params");
            continue;
        }

        $job = unserialize($serial);
        $result = AdvancedSearch::runQueuedQuery($job);

        if ($redis->ping() != 1) connectRedis();
        $redis->setex("$key:result", (int) ($job['cacheTime'] ?? 900), serialize($result));
        $redis->del("$key:params");
        $redis->del($key);
    } catch (Exception $e) {
        if (isset($key)) {
            $redis->del($key);
            $redis->del("$key:params");
        }
        Util::out(print_r($e, true));
    }
}
