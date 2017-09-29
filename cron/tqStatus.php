<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$serverStatus = "OFFLINE";
$loggedIn = 0;
$minute = date('Hi');
if ($minute >= 1100 && $minute <= 1105) {
    $redis->set('tqStatus', 'OFFLINE'); // Just in case the result is cached on their end as online
    $redis->set('tqCount', 0);
} else {
    $root = CrestTools::getJSON($crestServer);
    $version = @$root['serverVersion'];
    if ($version != null) {
        $redis->set('tqServerVersion', $version);
    }


    $serverStatus = $root == 0 ? 'UNKNOWN' : (isset($root['serviceStatus']) ? strtoupper($root['serviceStatus']) : 'OFFLINE');
    $loggedIn = (int) @$root['userCount'];
    $loggedIn = $loggedIn == 0 ? $serverStatus : number_format($loggedIn, 0);

    $redis->set('tqStatus', $serverStatus);
    $redis->set('tqCount', $loggedIn);
}
$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$killCount = number_format($killsLastHour->count(), 0);
$redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $killCount]));

$message = apiStatus(null, 'ttlc:esiSuccess', 'ttlc:esiFailure', "Issues with CCP's ESI API - some killmails may be delayed.");
$message = apiStatus($message, 'ttlc:CrestSuccess', 'ttlc:CrestFailure', "Issues with CCP's CREST API - some killmails may be delayed.");
$message = apiStatus($message, 'ttlc:XmlSuccess', 'ttlc:XmlFailure', "Issues with CCP's XML API - some killmails may be delayed.");
$message = apiStatus($message, 'ttlc:AuthSuccess', 'ttlc:AuthFailure', "Issues with CCP's SSO API - some killmails may be delayed.");
$redis->setex('tq:apiStatus', 300, $message);

function apiStatus($prevMessage, $success, $fail, $notification)
{
    if ($prevMessage != null) return $prevMessage;

    $success = new RedisTtlCounter($success, 300);
    $fail = new RedisTtlCounter($fail, 300);
    $sCount = $success->count();
    $fCount = $fail->count();
    $total = $sCount + $fCount;
    if ($total < 100) return null;
    if ($fCount / $total >= .9) return $notification;
    return null;
}
