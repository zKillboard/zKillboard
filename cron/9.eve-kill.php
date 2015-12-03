<?php

require_once '../init.php';

// Send the mails to eve-kill cuz we're nice like that
$queueShare = new RedisQueue('queueShare');
do {
    $killID = $queueShare->pop();
    if ($killID == null) continue;

    $hash = $mdb->findField('crestmails', 'hash', ['killID' => $killID, 'processed' => true]);
    Util::getData("https://beta.eve-kill.net/crestmail/$killID/$hash/", 0);
} while ($killID != null);
