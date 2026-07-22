<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once '../init.php';

if (isset($cronForks[basename(__FILE__)]) && $mt > $cronForks[basename(__FILE__)]) exit();

if ($redis->get("zkb:reinforced") == true) {
    exit();
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    $candidates = $mdb->find(DailyStats::COLLECTION, ['updates' => ['$exists' => true]], ['type' => 1, 'id' => 1], 100);
    $row = null;
    $lockKey = null;
    foreach ($candidates as $candidate) {
        $updates = (array) ($candidate['updates'] ?? []);
        if (count($updates) == 0) {
            $mdb->getCollection(DailyStats::COLLECTION)->updateOne(
                ['_id' => $candidate['_id'], 'updates' => []],
                ['$unset' => ['updates' => 1]]
            );
            continue;
        }
        $candidateLockKey = "zkb:stats_monthly:{$candidate['_id']}";
        if ($redis->set($candidateLockKey, "true", ['nx', 'ex' => 1800]) === true) {
            $row = $candidate;
            $lockKey = $candidateLockKey;
            break;
        }
    }

    if ($row == null && count($candidates) == 0) {
        if ($mt == 0) {
            sleep(1);
        } else {
            break;
        }
        continue;
    }
    if ($row == null) {
        usleep(25000);
        continue;
    }

    try {
        DailyStats::rebuildMonthly($row);
    } catch (Exception $ex) {
        Util::out(print_r($ex, true));
    } finally {
        $redis->del($lockKey);
    }
}
