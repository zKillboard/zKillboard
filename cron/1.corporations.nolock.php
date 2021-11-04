<?php

pcntl_fork();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = EveOnlineSSO::getSSO();

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:noapi") == "true") exit();

$chars = new RedisTimeQueue("zkb:characterID", 86400);
$esi = new RedisTimeQueue('tqCorpApiESI', 3600);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $corpIDRaw = $esi->next();
    $corpID = (int) $corpIDRaw;
    if ($corpID > 0) {
        if ($redis->get("esi-fetched:$corpID") == "true") continue;
        
        $row = $mdb->findDoc("scopes", ['corporationID' => $corpID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row == null) {
            $esi->remove($corpIDRaw);
            continue;
        }

        $charID = $row['characterID'];
        $refreshToken = $row['refreshToken'];
        $params = ['row' => $row, 'esi' => $esi, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'corpID' => $corpID];

        $refreshToken = $row['refreshToken'];
        $accessToken = $redis->get("oauth2:$charID:$refreshToken");
        if ($accessToken == null) {
            $accessToken = $sso->getAccessToken($refreshToken);
            if (@$accessToken['error'] == "invalid_grant") {
                $mdb->remove("scopes", $row);
                sleep(1);
                continue;
            }
            $redis->setex("oauth2:$charID:$refreshToken", 900, $accessToken);
        }
        $killmails = $sso->doCall("$esiServer/v1/corporations/$corpID/killmails/recent/", [], $accessToken);
        success(['corpID' => $corpID, 'row' => $row, 'esi' => $esi], $killmails);
        $redis->setex("esi-fetched:$corpID", 300, "true");
    } else {
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];

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

    $name = Info::getInfoField('characterID', $charID, 'name');
    $corpName = Info::getInfoField('corporationID', $corpID, 'name');
    if ($corpName == "") $corpName = "Corporation $corpID";
    $verifiedKey = "apiVerified:$corpID";
    $corpVerified = $redis->get($verifiedKey);
    if ($corpVerified === false) {
        Util::out("$corpName ($name) is now verified.", $charID);
    }
    $redis->setex($verifiedKey, 86400, time());

    if ($newKills > 0) {
        if ($name === null) $name = $charID;
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        Util::out("$newKills kills added by corp $corpName");
    }

    if ($redis->get("recentKillmailActivity:$corpID") == "true") $esi->setTime($corpID, time() + 301);
}
