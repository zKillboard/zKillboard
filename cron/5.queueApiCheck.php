<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$queueApiCheck = new RedisQueue('queueApiCheck');
$esi = new RedisTimeQueue('tqApiESI', 3600);

$bumped = [];
$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueApiCheck->pop();
    if ($killID > 0) {
        $killmail = $mdb->findDoc("killmails", ['killID' => $killID]);

        // Only do this fo rrecent killmails
        if ($killmail['dttm']->sec < (time() - 7200)) continue;

        $involved = $killmail['involved'];
        foreach ($involved as $entity) {
            $charID = @$entity['characterID'];

            $lastChecked = $redis->get("apiVerified:$charID");
            $redis->setex("recentKillmailActivity:$charID", 7200, "true");

            if ($lastChecked > 0 && time() - $lastChecked > 120 && !in_array($charID, $bumped)) {
                $esi->setTime($charID, 0);
                $bumped[] = $charID;
            }
        }
    } else sleep(1);
}
