<?php

$mt = 6; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once '../init.php';

if ($redis->get("zkb:reinforced") == true) {
    exit();
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    $candidates = $mdb->find(DailyStats::COLLECTION, ['update' => ['$gt' => 0]], ['update' => -1], 100, ['type' => 1, 'id' => 1, 'day' => 1, 'update' => 1]);
    $row = null;
    $lockKey = null;
    foreach ($candidates as $candidate) {
        $candidateLockKey = "zkb:dailystats:{$candidate['type']}:{$candidate['id']}:{$candidate['day']}";
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

    $type = $row['type'];
    $id = $row['id'];
    $day = $row['day'];
    $startUpdate = (int) $row['update'];

    try {
        DailyStats::rebuild($type, $id, $day, $startUpdate);
        $mdb->getCollection(DailyStats::COLLECTION)->updateOne(
            ['type' => $type, 'id' => $id, 'day' => $day, 'update' => $startUpdate],
            ['$set' => ['update' => 0]]
        );
    } catch (Exception $ex) {
        Util::out(print_r($ex, true));
    } finally {
        $redis->del($lockKey);
    }
}
