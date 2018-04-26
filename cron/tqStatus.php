<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$minute = date('Hi');
if ($minute >= 1100 && $minute <= 1105) {
    $redis->set('tqStatus', 'OFFLINE'); // Just in case the result is cached on their end as online
    $redis->set('tqCount', 0);
} else {
    $guzzler = new Guzzler();
    $guzzler->call("$esiServer/v1/status/", "success", "fail");
    $guzzler->finish();
}

$serverStatus = $redis->get("tqStatus");
$loggedIn = $redis->get("tqCount");
$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$killCount = number_format($killsLastHour->count(), 0);
$redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $killCount]));

$message = "";
$message = apiStatus(null, 'esi', "Issues with CCP's ESI API - some killmails may be delayed.");
$message = apiStatus($message, 'crest', "Issues with CCP's CREST API - some killmails may be delayed.");
$message = apiStatus($message, 'xml', "Issues with CCP's XML API - some killmails may be delayed.");
$message = apiStatus($message, 'sso', "Issues with CCP's SSO API - some killmails may be delayed.");
$redis->setex('tq:apiStatus', 300, $message);

function success($guzzler, $params, $content)
{
    global $redis;

    if ($content == "") return;

    $root = json_decode($content, true);
    $version = $root['server_version'];
    if ($version != null) {
        $redis->set('tqServerVersion', $version);
    }

    $loggedIn = (int) @$root['players'];
    $redis->set('tqCountInt', $loggedIn);

    $serverStatus = $loggedIn > 100 ? 'ONLINE' : 'OFFLINE';
    $loggedIn = $loggedIn == 0 ? $serverStatus : number_format($loggedIn, 0);

    $redis->set('tqStatus', $serverStatus);
    $redis->set('tqCount', $loggedIn);
}

function fail($guzzler, $params, $ex)
{
    global $redis;

    $redis->set('tqStatus', 'UNKNOWN');
    $redis->set('tqCount', 0);
    $redis->set('tqCountInt', 0);
}

function apiStatus($prevMessage, $apiType, $notification)
{
    if ($prevMessage != null) return $prevMessage;

    $sCount = Status::getStatus($apiType, true);
    $fCount = Status::getStatus($apiType, false);
    $total = $sCount + $fCount;
    if ($total < 100) return null;
    if ($fCount / $total >= .5) return $notification;
    return null;
}
