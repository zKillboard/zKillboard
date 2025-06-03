<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

$kvc = new KVCache($mdb, $redis);

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();
if ($kvc->get("zkb:universeLoaded") != "true") exit();

$guzzler = new Guzzler(10);
$esimails = $mdb->getCollection("esimails");

$mdb->set("crestmails", ['processed' => 'fetching'], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => 'processing'], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => ['$exists' => false]], ['processed' => false], true);

$minute = date("Hi");
while ($minute == date("Hi")) {
    $rows = $mdb->find("crestmails", ['processed' => false], ['killID' => -1], 10);
    foreach ($rows as $row) {
        $killID = $row['killID'];
        $hash = $row['hash'];
    
        if (strlen($hash) != 40) {
            // Invalid hash, wtf
            Util::out("removing invalid hash $killID $hash " . @$row['source']);
            $mdb->remove('crestmails', ['killID' => $killID, 'hash' => $hash]);
            continue;
        }

        $raw = Kills::getEsiKill($killID);
        if ($raw != null) {
            $mdb->set("crestmails", ['killID' => $killID, 'hash' => $hash], ['processed' => 'fetched']);
            $redis->zadd("tobeparsed", $killID, $killID);
            continue;
        }

        $mdb->set("crestmails", $row, ['processed' => 'fetching']);
        $guzzler->call("$esiServer/v1/killmails/$killID/$hash/", "success", "fail", ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails]);
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
            $mdb->set("crestmails", $row, ['processed' => 'invalid', 'code' => $code]);
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
    $mdb->set("crestmails", ['killID' => $row['killID'], 'hash' => $row['hash']], ['processed' => 'fetched']);
    $killID = $doc['killmail_id'];
    $params['redis']->zadd("tobeparsed", $killID, $killID);
    if ($redis->get("tobefetched") > 0) $redis->incr("tobefetched", -1);
}
