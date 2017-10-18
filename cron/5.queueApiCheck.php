<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$queueApiCheck = new RedisQueue('queueApiCheck');
$esi = new RedisTimeQueue('tqApiESI', 3600);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $killID = $queueApiCheck->pop();
    if ($killID > 0) {
        $killmail = $mdb->findDoc("killmails", ['killID' => $killID]);
        $involved = $killmail['involved'];
        foreach ($involved as $entity) {
            $charID = @$entity['characterID'];
            $lastChecked = $redis->get("apiVerified:$charID");
            if ($lastChecked > 0 && time() - $lastChecked > 120) {
                $esi->setTime($charID, 0);
            }
        }
    } else sleep(1);
}
