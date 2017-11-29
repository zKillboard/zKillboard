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
        if ($killmail['dttm']->sec < (time() - 3600)) continue;

        $involved = $killmail['involved'];
        foreach ($involved as $entity) {
            $charID = @$entity['characterID'];
            $corpID = Info::getInfoField("characterID", $charID, "corporationID");

            $lastChecked = $redis->get("apiVerified:$charID");
            $redis->setex("recentKillmailActivity:$charID", 3600, "true");
            $redis->setex("recentKillmailActivity:$corpID", 3600, "true");

            if ($lastChecked > 0 && (time() - $lastChecked) > 300 && !in_array($charID, $bumped)) {
                $esi->setTime($charID, 1);
                $bumped[] = $charID;
            }
            if ($lastChecked > 0 && (time() - $lastChecked) > 300 && !in_array($corpID, $bumped)) {
                $esi->setTime($corpID, 1);
                $bumped[] = $corpID;
            }
        }
    } else sleep(1);
}
