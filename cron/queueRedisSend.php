<?php

require_once '../init.php';

global $redisQServer;

if ($redisQServer == null) 
{
	$redis->del("queueRedisQ");
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
    $zkb = $mdb->findField('killmails', 'zkb', ['killID' => $killID]);

    $zkb['href'] = "https://public-crest.eveonline.com/killmails/$killID/".$zkb['hash'].'/';
    unset($rawmail['_id']);

    $package = ['killID' => $killID, 'killmail' => $rawmail, 'zkb' => $zkb];

    RedisQ\Action::queue($redisQServer, $redisQAuthUser, $redisQAuthPass, $package);
}
