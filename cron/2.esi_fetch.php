<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:noapi") == "true") exit();

$guzzler = new Guzzler(1);
$esimails = $mdb->getCollection("esimails");

$mdb->set("crestmails", ['processed' => 'fetching'], ['processed' => false], true);

$count = 0;
$minute = date("Hi");
while ($minute == date("Hi")) {
    $rows = $mdb->find("crestmails", ['processed' => false], [], 10);
    foreach ($rows as $row) {
        $killID = $row['killID'];
        $hash = $row['hash'];

        $raw = Kills::getEsiKill($killID);
        if (false && $raw != null) {
            $mdb->set("crestmails", $row, ['processed' => 'fetched']);
            $redis->zadd("tobeparsed", $killID, $killID);
            continue;
        }

        $mdb->set("crestmails", $row, ['processed' => 'fetching']);
        $guzzler->call("$esiServer/v1/killmails/$killID/$hash/", "success", "fail", ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails]);
        $count++;
        $guzzler->finish();
    }
    if (sizeof($rows) == 0) {
        $guzzler->sleep(1);
    }
}
$guzzler->finish();

function fail($guzzler, $params, $ex) {
    $mdb = $params['mdb'];
    $row = $params['row'];

    $code = $ex->getCode();
    switch ($code) {
        case 400:
        case 404:
            $mdb->set("crestmails", $row, ['processed' => 'failed', 'code' => $code]);
            break;
        case 422:
            //$mdb->set("crestmails", $row, ['processed' => 'failed', 'code' => $code]);
            $mdb->remove("crestmails", $row);
            break;
        case 0:
        case 420:
        case 500:
        case 502: // Do nothing, the server messed up and we'll try again in a minute
        case 503:
        case 504:
            break;
        default:
            Util::out("esi fetch failure ($code): " . $ex->getMessage());
    }
}

function success(&$guzzler, &$params, &$content) {
    global $redis;

    $mdb = $params['mdb'];
    $row = $params['row'];

    if ($content == "") {
        $mdb->set("crestmails", $row, ['processed' => 'empty']);
        return;
    }


    $esimails = $params['esimails'];
    $doc = json_decode($content, true);

    try {
        $esimails->insert($doc);
    } catch (Exception $ex) {
        // argh
    }
    $mdb->set("crestmails", $row, ['processed' => 'fetched']);
    $killID = $doc['killmail_id'];
    $params['redis']->zadd("tobeparsed", $killID, $killID);
    if ($redis->get("tobefetched") > 0) $redis->incr("tobefetched", -1);
}
