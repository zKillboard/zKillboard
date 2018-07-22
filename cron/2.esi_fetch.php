<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$guzzler = new Guzzler(25, 10);
$esimails = $mdb->getCollection("esimails");

$mdb->set("crestmails", ['processed' => ['$exists' => false]], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => ['$ne' => true]], ['processed' => false], true);

$minute = date("Hi");
while ($minute == date("Hi")) {
    $row = $mdb->findDoc("crestmails", ['processed' => false], ['killID' => 1]);
    if ($row != null) {
        $killID = $row['killID'];
        $hash = $row['hash'];

        $mdb->set("crestmails", $row, ['processed' => 'fetching']);

        $url = "$esiServer/v1/killmails/$killID/$hash/";
        $params = ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails];
        $guzzler->call($url, "success", "fail", $params);
    }
    else {
        $guzzler->tick();
        usleep(100000);
    }
}
$guzzler->finish();

function fail($guzzler, $params, $ex) {
    $mdb = $params['mdb'];
    $row = $params['row'];

    $code = $ex->getCode();
    switch ($code) {
        case 500:
            //Util::out("esi fetch failure ($code): " . $ex->getMessage() . "\n" . print_r($guzzler->getLastHeaders(), true));
            break;
        case 404:
        case 422:
            $mdb->remove("crestmails", $row);
            break;
        case 0:
        case 420:
        case 502: // Do nothing, the server messed up and we'll try again in a minute
        case 503:
        case 504:
            break;
        default:
            Util::out("esi fetch failure ($code): " . $ex->getMessage());
    }
}

function success(&$guzzler, &$params, &$content) {
    if ($content == "") return;

    $mdb = $params['mdb'];
    $row = $params['row'];

    $esimails = $params['esimails'];
    $doc = json_decode($content, true);

    try {
        $esimails->insert($doc);
    } catch (Exception $ex) {
        // argh
    }
    $mdb->set("crestmails", $row, ['processed' => true]);

    $queueProcess = new RedisQueue('queueProcess');
    $queueProcess->push($params['killID']);
    $killsLastHour = new RedisTtlCounter('killsLastHour');
    $killsLastHour->add($row['killID']);
}
