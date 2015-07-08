<?php

require_once '../init.php';

$topics[] = '/topic/kills';

$stomp = new Stomp($stompServer, $stompUser, $stompPassword);

$stomp->setReadTimeout(1);
foreach ($topics as $topic) {
    $stomp->subscribe($topic, array('id' => 'zkb-'.$baseAddr, 'persistent' => 'true', 'ack' => 'client', 'prefetch-count' => 1));
}

$stompCount = 0;
$timer = new Timer();

while ($timer->stop() <= 59000) {
    $frame = $stomp->readFrame();
    if (!empty($frame)) {
        $killdata = json_decode($frame->body, true);
        $killID = (int) $killdata['killID'];

        if ($killID == 0) {
            continue;
        }
        $hash = $hash = Killmail::getCrestHash($killID, $killdata);
        $killdata['killID'] = $killID; // Make sure its an int
        if (!$mdb->exists('apimails', ['killID' => $killID])) {
            $mdb->insertUpdate('apimails', $killdata);
        }
        if (!$mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash])) {
            ++$stompCount;
            $i = $mdb->getCollection('crestmails')->insert(['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'stomp', 'added' => $mdb->now()]);
        }
        if (!$mdb->exists('stompmails', ['killID' => $killID])) {
            $mdb->save('stompmails', $killdata);
        }
        $stomp->ack($frame->headers['message-id']);
    }
}
if ($stompCount > 0) {
    Util::out("New kills from STOMP: $stompCount");
}
