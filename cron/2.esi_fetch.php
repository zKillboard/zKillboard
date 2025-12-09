<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once "../init.php";

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

$guzzler = new Guzzler(10);
$esimails = $mdb->getCollection("esimails");

$mdb->set("crestmails", ['processed' => 'later'], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => 'fetching'], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => 'processing'], ['processed' => false], true);
$mdb->set("crestmails", ['processed' => ['$exists' => false]], ['processed' => false], true);

$checked = [];
$minute = date("Hi");
$HEADERS = [];
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
            // Do we already have a good link for this killID?
            $count = $mdb->count("crestmails", ['killID' => $killID, 'processed' => true]);
            if ($count > 0) {
                $mdb->set("crestmails", ['killID' => $killID, 'processed' => ['$ne' => true]], ['processed' => 'invalid/duplicate'], true);
            } else {
              $mdb->set("crestmails", ['killID' => $killID, 'hash' => $hash], ['processed' => 'delayed']);
            }
            continue;
        }
        if (in_array($killID, $checked)) {
            // we've already pulled this one?  check again later
            Util::out("ESI Fetch $killID $hash ... later");
            $mdb->set("crestmails", ['killID' => $killID, 'hash' => $hash], ['processed' => 'later']);
            continue;
        }
        $checked[] = $killID;

        $bucketRemaining = (int) ($redis->get("esi:ratelimit:killmail") ?? 3600);
        if ($bucketRemaining < 100) sleep(2); // bucket getting empty, slow down
        $mdb->set("crestmails", $row, ['processed' => 'fetching']);
        $guzzler->call("$esiServer/killmails/$killID/$hash/", "success", "fail", ['row' => $row, 'mdb' => $mdb, 'redis' => $redis, 'killID' => $killID, 'esimails' => $esimails]);
break;
    }
    if (sizeof($rows) == 0) {
        $guzzler->sleep(1);
    }
}
$guzzler->finish();

function fail($guzzler, $params, $ex) {
    global $mdb, $redis;

    $HEADERS = $params['HEADERS'];
    $remaining = @$HEADERS['x-ratelimit-remaining'][0] ?? 3600;
    $redis->setex("esi:ratelimit:killmail", 900, $remaining);

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

    $HEADERS = $params['HEADERS'];
    $remaining = @$HEADERS['x-ratelimit-remaining'][0] ?? 3600;
    $redis->setex("esi:ratelimit:killmail", 900, $remaining);

    $mdb = $params['mdb'];
    $row = $params['row'];

    if ($content == "") {
        $mdb->set("crestmails", $row, ['processed' => 'empty']);
        return;
    }

    $esimails = $params['esimails'];
    $doc = json_decode($content, true);

    try {
        $esimails->insertOne($doc);

        $unixtime = strtotime($doc['killmail_time']);
        $mdb->set("crestmails", ['killID' => $row['killID'], 'hash' => $row['hash']], ['processed' => 'delayed', 'epoch' => $unixtime]);
        $killID = $doc['killmail_id'];
        if ($redis->get("tobefetched") > 0) $redis->incr("tobefetched", -1);
    } catch (Exception $ex) {
        // argh
    }
}
