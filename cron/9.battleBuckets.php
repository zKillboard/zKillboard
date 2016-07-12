<?php

require_once '../init.php';

$time = time();
$time = $time - ($time % 300);
$key = "key:$time";
if ($redis->get($key) == 1) {
    exit();
}

$battles = $redis->sMembers('battleBuckets');
$battleSize = (@$battleSize == 0 ? 200 : $battleSize);
$days7 = 7 * 86400;

$bigBattles = [];
foreach ($battles as $battleBucket) {
    $kills = $redis->sCard($battleBucket);
    if ($kills == 0) {
        $redis->sRem('battleBuckets', $battleBucket);
    }
    if ($kills >= $battleSize) {
        $bigBattles[] = $battleBucket;
        $redis->setex("battle:$battleBucket", $days7, $kills);
    }
}

arsort($bigBattles);
foreach ($bigBattles as $bigBattle) {
    $ex = explode(':', $bigBattle);
    $systemID = $ex[2];
    $time = $ex[1];
    if (!$redis->sIsMember('battlesAnnounced', $bigBattle)) {
        $redis->sAdd('battlesAnnounced', $bigBattle);
    }
}

$redis->setex($key, 3600, 1);
