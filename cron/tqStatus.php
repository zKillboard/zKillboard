<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$root = CrestTools::getJSON($crestServer);
$version = @$root['serverVersion'];
if ($version != null) $redis->set("tqServerVersion", $version);

if ($root == 0) {
	$serverStatus = 'UNKNOWN';
} else {
	$serverStatus = isset($root['serviceStatus']) ? strtoupper($root['serviceStatus']) : 'OFFLINE';
}
$loggedIn = (int) @$root['userCount'];

if ($loggedIn == 0) {
	$loggedIn = $serverStatus;
} else {
	$loggedIn = number_format($loggedIn, 0);
}

$redis->set('tqStatus', $serverStatus);
$redis->set('tqCount', $loggedIn);

$crestFailure = new RedisTtlCounter('ttlc:CrestFailure', 300);
$count = $crestFailure->count();
$remaining = $mdb->count('crestmails', ['processed' => false]);
$message = null;
if ($count > 100 && $remaining > 100) {
	$remaining = number_format($remaining);
	$message = "Issues accessing CREST - Killmails may not post - $count failures in last 5 minutes - backlog of $remaining killmails";
}

$xmlSuccess = new RedisTtlCounter('ttlc:XmlSuccess', 300);
$xmlFailure = new RedisTtlCounter('ttlc:XmlFailure', 300);
$s = $xmlSuccess->count();
$f = $xmlFailure->count();
if ($message == null && $xmlFailure->count() > (10 * $xmlSuccess->count())) {
	$message = "Issues accessing Killmail XML API - Killmails won't populate from API at this time - $s Successful / $f Failed calls in last 5 minutes";
}

$redis->setex("tq:crestStatus", 300, $message);

// Set the top kill for api requests to use
$topKillID = $mdb->findField('killmails', 'killID', [], ['killID' => -1]);
$redis->setex("zkb:topKillID", 86400, $topKillID);
