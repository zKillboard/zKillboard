<?php

$mt = 4; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once '../init.php';

if (isset($cronForks[basename(__FILE__)]) && $mt > $cronForks[basename(__FILE__)]) exit();
if ($redis->get('zkb:reinforced') == true) exit();

$minute = date('Hi');
while ($minute == date('Hi')) {
    $row = null;
    $lockKey = null;
    $candidates = $mdb->getCollection(DailyStats::COLLECTION)->find(
        ['updates' => ['$exists' => true], DailyStats::MONTH_FIELD => ['$exists' => true]],
        ['sort' => ['type' => 1, 'id' => 1, DailyStats::MONTH_FIELD => 1], 'limit' => 100]
    );
    foreach ($candidates as $candidate) {
        if (count((array) ($candidate['updates'] ?? [])) == 0) {
            $mdb->getCollection(DailyStats::COLLECTION)->updateOne(
                ['_id' => $candidate['_id'], 'updates' => []],
                ['$unset' => ['updates' => 1]]
            );
            continue;
        }

        $candidateLock = "zkb:stats_monthly:{$candidate['_id']}";
        if ($redis->set($candidateLock, true, ['nx', 'ex' => 1800]) === true) {
            $row = $candidate;
            $lockKey = $candidateLock;
            break;
        }
    }

    if ($row == null) {
        if ($mt == 0) usleep(250000);
        else break;
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
