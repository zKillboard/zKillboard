<?php

require_once '../init.php';

$root = CrestTools::getJSON('https://public-crest.eveonline.com/');

$serverStatus = @$root['serviceStatus']['eve'];
if ($serverStatus == null) {
    $serverStatus = 'OFFLINE';
} else {
    $serverStatus = strtoupper($serverStatus);
}
$loggedIn = (int) @$root['userCounts']['eve'];

if ($loggedIn == 0) {
    $loggedIn = $serverStatus;
} else {
    $loggedIn = number_format($loggedIn, 0);
}

$redis->set('tqStatus', $serverStatus);
$redis->set('tqCount', $loggedIn);
