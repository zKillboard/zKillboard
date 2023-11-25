<?php

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

global $redisQServer;
$ch = null;

$topKillID = max(1, $mdb->findField('killmails', 'killID', [], ['killID' => -1]));

if ($redisQServer == null) {
    $redis->del('queueRedisQ');
    exit();
}

$queueRedisQ = new RedisQueue('queueRedisQ');
$queuePublish = new RedisQueue('queuePublish');

$minute = date('Hi');
while (date('Hi') == $minute) {
    $killID = $queueRedisQ->pop();
    if ($killID == null) {
        sleep(1);
        continue;
    }
    if ($redis->get("tobefetched") > 1000 && $killID < ($topKillID - 10000)) continue;

    $rawmail = Kills::getEsiKill($killID);
    $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
    $zkb = $killmail['zkb'];
    $zkb['npc'] = @$killmail['npc'];
    $zkb['solo'] = @$killmail['solo'];
    $zkb['awox'] = @$killmail['awox'];
    $zkb['labels'] = @$killmail['labels'];

    $zkb['href'] = "$esiServer/v1/killmails/$killID/".$zkb['hash'].'/';
    unset($rawmail['_id']);

    $package = ['killID' => $killID, 'killmail' => $rawmail, 'zkb' => $zkb];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$redisQServer/queue.php");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "pass=$redisQAuthPass&package=" . urlencode(json_encode($package, JSON_UNESCAPED_SLASHES)));

    $result = json_decode(curl_exec($ch), true);
    if ($result == NULL || @$result['success'] != true) {
        $queueRedisQ->push($killID);
        sleep(1);
    } else {
        $queuePublish->push($killID);
    }
}
