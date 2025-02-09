<?php

$mt = 5; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); 

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = ZKillSSO::getSSO();

if ($redis->get("zkb:noapi") == "true") exit();

$chars = new RedisTimeQueue("zkb:characterID", 86400);
$esi = new RedisTimeQueue('tqCorpApiESI', $esiCorpKm);

if ($mt == 0 && (date("i") == 44 || $esi->size() < 100)) {
       $corpIDs = $mdb->getCollection("scopes")->distinct("corporationID", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
       foreach ($corpIDs as $corpID) $esi->add($corpID);
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    $corpIDRaw = $esi->next();
    $corpID = (int) $corpIDRaw;
    if ($corpID > 0) {
        if ($redis->get("esi-fetched:$corpID") == "true") continue;
        
        $row = $mdb->findDoc("scopes", ['corporationID' => $corpID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row == null) {
            Util::out("Removing null row $corpID");
            $esi->remove($corpIDRaw);
            continue;
        }

        $charID = $row['characterID'];
        $refreshToken = $row['refreshToken'];
        $params = ['row' => $row, 'esi' => $esi, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'corpID' => $corpID];

        $timer = new Timer();
        $refreshToken = $row['refreshToken'];
        $accessToken = $sso->getAccessToken($refreshToken);
        $redis->rpush("timer:sso", round($timer->stop(), 0));
        if (is_array($accessToken) && @$accessToken['error'] == "invalid_grant") {
            Util::out("Removing invalid_grant row for corp km scope");
            $mdb->remove("scopes", $row);
            sleep(1);
            continue;
        }

        $timer = new Timer();
        $killmails = $sso->doCall("$esiServer/v1/corporations/$corpID/killmails/recent/", [], $accessToken);
        success(['corpID' => $corpID, 'row' => $row, 'esi' => $esi, 'timer' => $timer], $killmails);
        $redis->setex("esi-fetched:$corpID", 300, "true");
        usleep(100000);
    } else {
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];
    $timer = $params['timer'];
    $redis->rpush("timer:corporations", round($timer->stop(), 0));

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        print_r($kills);
        return;
    }
    if (isset($kills['error'])) {
        // Something went wrong, reset it and try again later
        $esi->add($row['corporationID'], 0);
        return;
    }
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash);
    }

    $charID = $row['characterID'];
    $corpID = $params['corpID'];

    $successes = 1 + ((int) @$row['successes']);
    $modifiers = ['corporationID' => $corpID, 'lastFetch' => $mdb->now(), 'successes' => $successes];
    if (!isset($row['added'])) $modifiers['added'] = $mdb->now();
    if (!isset($row['iterated'])) $modifiers['iterated'] = false;
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers);

    if ($newKills > 0) {
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        if ($corpName == "") $corpName = "Corporation $corpID";

        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        Util::out("$newKills kills added by corp $corpName");
    }

    if ($redis->get("recentKillmailActivity:corp:$corpID") == "true") $esi->setTime($corpID, time() + 301);
}
