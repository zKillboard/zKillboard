<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$queueApiCheck = new RedisQueue('queueApiCheck');
$esi = new RedisTimeQueue('tqApiESI', 3600); 
$esiCorp = new RedisTimeQueue('tqCorpApiESI', 3600);

$bumped = [];
$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueApiCheck->pop();
    if ($killID > 0) {
        $killmail = $mdb->findDoc("killmails", ['killID' => $killID]);

        // Only do this for recent killmails
        if ($killmail['dttm']->sec < (time() - 3600)) continue;

        $involved = $killmail['involved'];
        foreach ($involved as $entity) {
            $charID = ((int) @$entity['characterID']);
            $corpID = ((int) @$entity['corporationID']);

            if ($charID > 0) {
                $redis->setex("recentKillmailActivity:$charID", 3600, "true");
                $redis->setex("recentKillmailActivity:char:$charID", 3600, "true");
            }
            if ($charID > 1999999) {
                $redis->setex("recentKillmailActivity:$corpID", 3600, "true");
                $redis->setex("recentKillmailActivity:corp:$corpID", 3600, "true");
            }
        }
    } else sleep(1);
}
