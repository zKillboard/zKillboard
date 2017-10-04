<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $redisQServer;

if ($redisQServer == null) {
    $redis->del('queueRedisQ');
    exit();
}

$queueRedisQ = new RedisQueue('queueRedisQ');
$highKillID = $mdb->findDoc("killmails", [], ['killID' => -1]);
$maxKillID = $highKillID['killID'] - 1000000;

$minute = date('Hi');
while (date('Hi') == $minute) {
    $killID = $queueRedisQ->pop();
    if ($killID == null || $killID < $maxKillID ) {
        continue;
    }

    $rawmail = $mdb->findDoc("esimails", ['killmail_id' => $killID]);
    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    $zkb = $killmail['zkb'];
    $zkb['npc'] = @$killmail['npc'];
    $zkb['solo'] = @$killmail['solo'];
    $zkb['awox'] = @$killmail['awox'];

    $zkb['href'] = "$esiServer/v1/killmails/$killID/".$zkb['hash'].'/';
    unset($rawmail['_id']);

    $package = ['killID' => $killID, 'killmail' => $rawmail, 'zkb' => $zkb];

    $result = RedisQ\Action::queue($redisQServer, $redisQAuthUser, $redisQAuthPass, $package);
    if (@$result['success'] != true) {
        $queueRedisQ->push($killID);
        sleep(1);
    }
}
