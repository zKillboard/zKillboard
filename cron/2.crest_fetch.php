<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

global $baseAddr, $baseDir;

$crestmails = $mdb->getCollection('crestmails');
$rawmails = $mdb->getCollection('rawmails');
$killsLastHour = new RedisTtlCounter('killsLastHour');

$counter = 0;
$minute = date('Hi');

// Prepare curl, handler, and guzzler
$curl = new \GuzzleHttp\Handler\CurlMultiHandler();
$handler = \GuzzleHttp\HandlerStack::create($curl);
$client = new \GuzzleHttp\Client(['connect_timeout' => 10, 'timeout' => 10, 'handler' => $handler, 'User-Agent' => 'zkillboard.com']);

$mdb->set("crestmails", ['processed' => ['$exists' => false]], ['processed' => false], ['multi' => true]);
$mdb->set("crestmails", ['processed' => ['$ne' => true]], ['processed' => false]);

$minute = date('Hi');
$count = 0;
$maxConcurrent = 10;
while ($minute == date('Hi')) {
    if ($redis->get("tqStatus") == "OFFLINE") break;
    $row = $mdb->findDoc("crestmails", ['processed' => false], ['killID' => -1]);
    if ($row != null) {
        $count++;
        $killID = (int) $row['killID'];
        $hash = $row['hash'];
        $url = "$crestServer/killmails/$killID/$hash/";

        $mdb->set("crestmails", $row, ['processed' => 'fetching']);
        $client->getAsync($url)->then(
            function($response) use ($mdb, $row, &$count) {
                $count--;
                handleFulfilled($mdb, $row, json_decode($response->getBody(), true));
            },
            function($connectionException) use ($mdb, $row, &$count) {
                $count--;
                handleRejected($mdb, $row, $connectionException->getCode());
            });
    }
    do {
        $curl->tick();
    } while ($count >= $maxConcurrent && $minute == date('Hi')) ;
    usleep(50000);
}
$curl->execute();


function handleFulfilled($mdb, $row, $rawKillmail)
{
    global $redis;

    $queueProcess = new RedisQueue('queueProcess');
    $killsLastHour = new RedisTtlCounter('killsLastHour');
    $crestSuccess = new RedisTtlCounter('ttlc:CrestSuccess', 300);

    $killID = (int) $row['killID'];
    $hash = $row['hash'];
    if (!$mdb->exists("rawmails", ['killID' => $killID])) {
        $mdb->save("rawmails", $rawKillmail);
    }

    $redis->rpush("esi2Fetch", "$killID:$hash");
    $mdb->set("crestmails", $row, ['processed' => true]);
    $killsLastHour->add($killID);
    $crestSuccess->add(uniqid());
}

function handleRejected($mdb, $row, $code)
{
    switch ($code) {
        case 0: // Timeout
        case 503: // Server error
            $mdb->set("crestmails", $row, ['processed' => false]);
            break;
        case 403: // Not authorized
        case 404: // Doesn't exist
            $mdb->remove("crestmails", $row);
            break;
        default:
            Util::out($row['killID'] . " crest fetch has error code $code");
            $mdb->set("crestmails", $row, ['processed' => 'error', 'errorCode' => $code]);
    }
    $crestFailure = new RedisTtlCounter('ttlc:CrestFailure', 300);
    $crestFailure->add(uniqid());
}
