<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$root = CrestTools::getJSON($crestServer);
$version = @$root['serverVersion'];
if ($version != null) {
    $redis->set('tqServerVersion', $version);
}

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
$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$killCount = number_format($killsLastHour->count(), 0);
$redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $killCount]));

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
$crestSuccess = new RedisTtlCounter('ttlc:CrestSuccess', 300);
$crestFailure = new RedisTtlCounter('ttlc:CrestFailure', 300);
$esiSuccess = new RedisTtlCounter('ttlc:esiSuccess', 300);
$esiFailure = new RedisTtlCounter('ttlc:esiFailure', 300);

$s = $xmlSuccess->count() + $crestSuccess->count() + $esiSuccess->count();
$f = $xmlFailure->count() + $crestFailure->count() + $esiFailure->count();
if ($message == null && $xmlFailure->count() > (10 * $xmlSuccess->count())) {
    $message = "Various issues with the APIs - cannot pull killmails at this time.";
}

$behind = $redis->llen("queueProcess") + $mdb->count('crestmails', ['processed' => false]);
if ($behind > 100 && $message == null) {
    $behind = number_format($behind);
    //$message = "Busy server - currently behind on processing $behind killmails";
}

$redis->setex('tq:crestStatus', 300, $message);

// Set the top kill for api requests to use
$topKillID = $mdb->findField('killmails', 'killID', [], ['killID' => -1]);
$redis->setex('zkb:topKillID', 86400, $topKillID);

$load = sys_getloadavg();
if ($load[0] > 10) {
    $redis->setex('zkb:allowAPI', 300, "no");
}

$redis->set("zkb:totalChars", $mdb->count("information", ['type' => 'characterID']));
$redis->set("zkb:totalCorps", $mdb->count("information", ['type' => 'corporationID']));
$redis->set("zkb:totalAllis", $mdb->count("information", ['type' => 'allianceID']));

$arr = [];
$greenTotal = 0;
$redTotal = 0;
for ($i = 0; $i < 7; $i++) {
    $green = "zkb:loot:green:" . date('Y-m-d', time() - ($i * 86400));
    $red = "zkb:loot:red:" . date('Y-m-d', time() - ($i * 86400));
    $greenTotal += $redis->get($green);
    $redTotal += $redis->get($red);
}
$arr[] = ['typeID' => 0, 'name' => 'Loot Fairy', 'dV' => $greenTotal, 'lV' => $redTotal];
$items = [29668, 40520];
$date = date('Ymd');
foreach ($items as $item) {
    $d =  new RedisTtlCounter("ttlc:item:$item:dropped", 86400 * 7);
    $dSize = $d->count();
    $l = new RedisTtlCounter("ttlc:item:$item:destroyed", 86400 * 7);
    $lSize = $l->count();
    $name = $item == 29668 ? "PLEX" : Info::getInfoField("typeID", $item, "name");
    $price = Price::getItemPrice($item, $date, true);
    $arr[] = ['typeID' => $item, 'name' => $name, 'price' => $price, 'dropped' => $dSize, 'destroyed' => $lSize, 'dV' => ($dSize * $price), 'lV' => ($lSize * $price)];
}
$redis->set("zkb:ttlc:items:index", json_encode($arr));

$i = Mdb::group("payments", ['characterID'], ['refTypeID' => '10', 'dttm' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1, 'dttm' => -1], 10);
Info::addInfo($i);
$redis->set("zkb:topDonators", json_encode($i));

$result = Mdb::group("sponsored", ['killID'], ['entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1], 6);
$sponsored = [];
foreach ($result as $kill) {
    if ($kill['iskSum'] <= 0) continue;
    $killmail = $mdb->findDoc("killmails", ['killID' => $kill['killID']]);
    Info::addInfo($killmail);
    $killmail['victim'] = $killmail['involved'][0];
    $killmail['zkb']['totalValue'] = $kill['iskSum'];

    $sponsored[$kill['killID']] = $killmail;
}
$redis->set("zkb:sponsored", json_encode($sponsored));
