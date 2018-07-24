<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$minute = date('Hi');
if ($minute >= 1100 && $minute <= 1105) {
    $redis->set('tqStatus', 'OFFLINE'); // Just in case the result is cached on their end as online
    $redis->set('tqCount', 0);
    $redis->set('tqCountInt', 0);
} else {
    // Not using Guzzle to prevent tq status conflicts and deadlock
    $root = @file_get_contents("$esiServer/v1/status/");
    success($root);
}

$serverStatus = $redis->get("tqStatus");
$loggedIn = $redis->get("tqCount");
$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$killCount = number_format($killsLastHour->count(), 0);
$redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $killCount]));

$message = ($redis->get("tqCountInt") == 0) ? "<a href='https://status.esiknife.space/' target='_blank'>We seem to be having an issue accessing the ESI API, nothing can be done at this time.</a>" : "";
$message = apiStatus($message, 'esi', "Issues with CCP's ESI API - some killmails may be delayed.");
$message = apiStatus($message, 'sso', "Issues with CCP's SSO API - some killmails may be delayed.");
if ($message == "" && $redis->llen("queueRelated") > 500) $message = "<a href='/ztop/'>Server is experiencing higher than normal load, your happy and pleasurable user experience has been ganked.</a>";
$redis->setex('tq:apiStatus', 300, $message);

function success($content)
{
    global $redis;

    if ($content == "") return fail();

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

function fail()
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
