<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $redisQServer;

if ($redisQServer == null) {
    $redis->del('queueRedisQ');
    exit();
}

$queueRedisQ = new RedisQueue('queueRedisQ');

$timer = new Timer();
while ($timer->stop() <= 59000) {
    $killID = $queueRedisQ->pop();
    if ($killID == null) {
        continue;
    }

    $rawmail = $mdb->findDoc('rawmails', ['killID' => $killID]);
    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    $zkb = $killmail['zkb'];

    $zkb['href'] = "$crestServer/killmails/$killID/".$zkb['hash'].'/';
    unset($rawmail['_id']);

    $package = ['killID' => $killID, 'killmail' => $rawmail, 'zkb' => $zkb];

    $result = RedisQ\Action::queue($redisQServer, $redisQAuthUser, $redisQAuthPass, $package);
    if (@$result['success'] != true) {
        $queueRedisQ->push($killID);
        sleep(1);
    }
}
