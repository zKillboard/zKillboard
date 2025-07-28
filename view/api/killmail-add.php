<?php

$app->contentType('application/json; charset=utf-8');
global $mdb, $redis, $ip;

// Basic verification
if ((int) $killID <= 0 || strlen($hash) != 40) return invalidRequest("Malformed id or hash");
$killID = (int) $killID;
Util::zout("api mail add $killID $hash");

// do we trust the poster?
if (((int) $redis->get("km:post:$ip")) > 5) {
    header('HTTP/1.0 401 Unauthorized');
    echo json_encode(['error' => 'you cannot be trusted']);
    return;
} 

// do we have the mail?
if ($mdb->findDoc('killmails', ['killID' => $killID]) == null) {
    try {
        $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false, 'source' => 'killmail-api-add', 'delay' => 0]);
    } catch (Exception $ex) {
        Util::zout(print_r($ex, true));
    }

    if ($redis->get("zkb:noapi") == "true") return invalidRequest("ESI is unavilable atm");

    // wait for the mail to be processed
    $timer = new Timer();
    $processed = null;
    do {
        usleep(100000);
        $processed = $mdb->findField("crestmails", "processed", ['killID' => $killID, 'hash' => $hash]);
    } while ($processed !== true && in_array($processed, [false, 'fetching', 'fetched', 'processing']) && $timer->stop() <= 28000);

    // Did we take too long?
    if ($timer->stop() > 28000) {
        header('HTTP/1.0 408 Timeout');
        echo json_encode(['error' => 'timeout']);
    }
        
    // is the mail valid?
    if ($processed !== true) return invalidRequest("Invalid id or hash");
} else {
    // update the delay to 0, manual posts always take priority
    $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['delay' => 0]);
}

// return URL for the mail
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
echo json_encode(['status' => 'success', 'url' => "https://zkillboard.com/kill/${killID}/"]);

function invalidRequest($reason) {
    global $IP, $redis;
    Util::zout("invalid request! $reason");

    $redis->incr("km:post:$IP");
    $redis->expire("km:post:$IP", 86400);

    header('HTTP/1.0 422 Invalid Format');
    echo json_encode(['status' => 'error', 'error' => $reason]);
}
