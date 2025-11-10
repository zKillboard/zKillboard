<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$queueApiCheck = new RedisQueue('queueApiCheck');
$esi = new RedisTimeQueue('tqApiESI', 3600); 
$esiCorp = new RedisTimeQueue('tqCorpApiESI', 3600);

$delta = 9600;

$bumped = [];
$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueApiCheck->pop();
    if ($killID > 0) {
        $killmail = $mdb->findDoc("killmails", ['killID' => $killID]);

        // Only do this for recent killmails
        if ($killmail['dttm']->sec < (time() - $delta)) continue;

        $involved = $killmail['involved'];
        foreach ($involved as $entity) {
            $charID = ((int) @$entity['characterID']);
            $corpID = ((int) @$entity['corporationID']);

            $redis->setex("recentKillmailActivity:$charID", $delta, "true");
            $redis->setex("recentKillmailActivity:$corpID", $delta, "true");

            $redis->setex("recentKillmailActivity:char:$charID", $delta, "true");
            $redis->setex("recentKillmailActivity:corp:$corpID", $delta, "true");
        }
    } else sleep(1);
}
