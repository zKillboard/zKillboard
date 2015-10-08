<?php

require_once '../init.php';

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

    $queueAuth = [['zkb' => 'RedisQ:auth:99apples']];

    $redisQServer = 'redisq.zkillboard.com';

    RedisQ\Action::queue('redisq.zkillboard.com', $redisQAuthUser, $redisQAuthPass, $package);
}
