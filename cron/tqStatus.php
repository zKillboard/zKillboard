<?php

require_once '../init.php';

$root = CrestTools::getJSON('https://public-crest.eveonline.com/');

if ($root == 0) {
	$serverStatus = 'UNKNOWN';
} else {
	$serverStatus = @$root['serviceStatus']['eve'];
	if ($serverStatus == null) {
		$serverStatus = 'OFFLINE';
	} else {
		$serverStatus = strtoupper($serverStatus);
	}
}
$loggedIn = (int) @$root['userCounts']['eve'];

if ($loggedIn == 0) {
	$loggedIn = $serverStatus;
} else {
	$loggedIn = number_format($loggedIn, 0);
}

$redis->set('tqStatus', $serverStatus);
$redis->set('tqCount', $loggedIn);

$crestFailure = new RedisTtlCounter('ttlc:CrestFailure', 300);
$count = $crestFailure->count();
$remaining = number_format($mdb->count('crestmails', ['processed' => false]), 0);
$message = $count > 100 ? "Issues accessing CREST - Killmails may not post - $count failures in last 5 minutes - backlog of $remaining killmails" : null;
$redis->setex("tq:crestStatus", 300, $message);
