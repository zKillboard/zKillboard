<?php

require_once '../init.php';

global $stompListen;
if ($stompListen != true) {
    exit();
}

$topics[] = '/topic/kills';

try {
    $stomp = new Stomp($stompServer, $stompUser, $stompPassword);
} catch (Exception $ex) {
    Util::out('Stomp error: '.$ex->getMessage());
    exit();
}

$stomp->setReadTimeout(1);
foreach ($topics as $topic) {
    $stomp->subscribe($topic, array('id' => 'zkb-'.$baseAddr, 'persistent' => 'true', 'ack' => 'client', 'prefetch-count' => 1));
}

$stompCount = 0;
$timer = new Timer();

while ($timer->stop() <= 59000) {
    sleep(1);
    $frame = $stomp->readFrame();
    if (!empty($frame)) {
        $killdata = json_decode($frame->body, true);
        $killID = (int) $killdata['killID'];

        if ($killID >= 0 && sizeof(@$killdata['attackers']) > 0 ) {
            $hash = Killmail::getCrestHash($killID, $killdata);
            $killdata['killID'] = (int) $killID;

            if (!$mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash])) {
                ++$stompCount;
                $i = $mdb->getCollection('crestmails')->insert(['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'stomp', 'added' => $mdb->now()]);
            }

            $stomp->ack($frame->headers['message-id']);
        }
    } else {
        break;
    }
}
if ($stompCount > 0) {
    Util::out("New kills from STOMP: $stompCount");
}
