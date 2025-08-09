<?php

use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$kvc = new KVCache($mdb, $redis);

$minute = (int) date('Hi');
if ($minute >= 1100 && $minute <= 1105) {
    $redis->set('tqStatus', 'OFFLINE'); // Just in case the result is cached on their end as online
    $redis->set('tqCount', 0);
    $redis->set('tqCountInt', 0);
    $redis->setex("zkb:noapi", 110, "true");
    exit();
} else {
    // Not using Guzzle to prevent tq status conflicts and deadlock
    for ($i = 0; $i <= 3; $i++) {
        $root = @file_get_contents("$esiServer/v1/status/");
        if ($root != "" ) {
            success($root);
            break;
        }
        sleep(5);
    }
}
$tqCountInt = (int) $redis->get("tqCountInt");
if (($minute >= 1058 && $minute <= 1105) || $tqCountInt < 1000) {
    Util::out("Flagging NO API (TQ Count: $tqCountInt)");
    $redis->setex("zkb:noapi", 110, "true");
} else if ($redis->get("zkb:noapi") == "true") {
    Util::out("Re-enabling API");
    $redis->del("zkb:noapi");
    // since everything else has likely quit by now due to noapi, we'll reexecute cron.sh
    exec('./cron/cron.sh >/dev/null 2>&1 &');
}

$serverStatus = $redis->get("tqStatus");
$loggedIn = $redis->get("tqCount");
$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
$killCount = $killsLastHour->count();
$redis->publish("public", json_encode(['action' => 'tqStatus', 'tqStatus' => $serverStatus, 'tqCount' => $loggedIn, 'kills' => $killCount]));

if ($tqCountInt < 1000) $message = "ESI offline. Enjoy your downtime.";
if ($tqCountInt < 1000 && (date('Hi') < 1058 || date ('Hi') > 1110)) $message = "ESI appears offline. Time to panic?";
else $message = ($redis->get("tqCountInt") == 0 || Status::getStatus('esi', false) >= 100) ? "<a href='/ztop/'>We seem to be having an issue accessing the ESI API, nothing can be done at this time.</a>" : "";
//$message = "ESI has been extremely slow and or timing out for 3+ weeks now. This is causing a delay in fetching killmails. The problem is with CCP servers, not zKillboard. There is nothing zKillboard can do at this time except hope CCP fixes these problems soon.";
$message = apiStatus($message, 'esi', "Issues with CCP's ESI API - some killmails may be delayed.");
$message = apiStatus($message, 'sso', "Issues with CCP's SSO API - some killmails may be delayed.");
//if ($message == "" && (Util::getRedisAvg('timer:characters', 1000) > 1000 || Util::getRedisAvg('timer:corporations', 100) > 1500)) $message = "<a href='/ztop/'>ESI endpoints are very slow atm, post kills manually for faster fetching...</a>";
if ($message == "" && $redis->llen("queueRelated") > 500) $message = "<a href='/ztop/'>Server is experiencing higher than normal load, your happy and pleasurable user experience has been ganked.</a>";
if ($message == null) $redis->del('tq:apiStatus');
else $redis->setex('tq:apiStatus', 300, $message);
$redis->publish("public", json_encode(['action' => 'message', 'message' => "$message"]));

function success($content)
{
    global $kvc, $redis, $mdb;

    if ($content == "") return fail();

    $root = json_decode($content, true);
    $version = $root['server_version'];
    if ($version != null) {
        $kvc->set('tqServerVersion', $version);
        $mdb->insertUpdate("versions", ['serverVersion' => $version], ['epoch' => time() + 120]);
    }

    $loggedIn = (int) @$root['players'];
    $redis->set('tqCountInt', $loggedIn);
    $serverStatus = $loggedIn > 100 ? 'ONLINE' : 'OFFLINE';

    $redis->set('tqStatus', $serverStatus);
    $redis->set('tqCount', $loggedIn);
    Util::out("TQ's status: $serverStatus w/ $loggedIn");
}

function fail()
{
    global $redis;

    $redis->set('tqStatus', 'UNKNOWN');
    $redis->set('tqCount', 0);
    $redis->set('tqCountInt', 0);
    Util::out("TQ status fail");
}

function apiStatus($prevMessage, $apiType, $notification)
{
    if ($prevMessage != null) return $prevMessage;

    $sCount = Status::getStatus($apiType, true);
    $fCount = Status::getStatus($apiType, false);
    $total = $sCount + $fCount;
    if ($total < 100) return null;
    //if ($fCount >= 100) return $notification;
    if ($fCount / $total >= .1) return $notification;
    return null;
}
