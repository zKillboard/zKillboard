<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);

global $redisQServer;
$ch = null;

$topKillID = max(1, $mdb->findField('killmails', 'killID', [], ['killID' => -1]));

if ($redisQServer == null) {
    $redis->del('queueRedisQ');
    exit();
}

$queueRedisQ = new RedisQueue('queueRedisQ');
$queueRedisQFail = new RedisQueue('queueRedisQFail');

while ($queueRedisQFail->size() > 0) $queueRedisQ->push($queueRedisQFail->pop());

$minute = date('Hi');
while (date('Hi') == $minute) {
    $redis->sort('queueRedisQ', ['alpha' => true, 'sort' => 'desc', 'store' => 'queueRedisQ']);
    $killID = $queueRedisQ->pop();
    if ($killID == null) {
        sleep(1);
        continue;
    }
    if ($beSocial !== true) continue; 
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
        Util::out("Failed to send to redisq: " . $killID . " ($result)");
        $rfkey = "zkb:redisqfail:$killID";
        $redis->incr($rfkey);
        $redis->expire($rfkey, 300);
        if (((int) $redis->get($rfkey)) <= 20) $queueRedisQFail->push($killID); // After 20 failures, we're giving up
        sleep(1);
    }
    sleep($redisQSleep);
}
