<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once '../init.php';

if (isset($cronForks[basename(__FILE__)]) && $mt > $cronForks[basename(__FILE__)]) exit();

$minute = date('Hi');
while ($minute == date('Hi')) {
    $key = null;
    try {        
        if ($redis->get("zkb:reinforced") == true) break;

        $key = $redis->spop('queueDailyStatsSet');
        if ($key == null) { usleep(100000); continue; }
        if ($redis->get("$key:result") !== false) continue;

        $serial = $redis->get("$key:params");
        if ($serial == null) continue;

        if ($redis->get($key) !== "PROCESSING") {
            $redis->del("$key:params");
            continue;
        }

        $job = unserialize($serial);
        $result = DailyStats::runQueuedQuery($job);

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
