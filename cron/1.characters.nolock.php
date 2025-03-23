<?php

$mt = 8; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = ZKillSSO::getSSO();

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:universeLoaded") != "true") exit();

$esiCorps = new RedisTimeQueue('tqCorpApiESI', 3600);
$esi = new RedisTimeQueue('tqApiESI', 3600);
if ($mt == 0 && (date('i') == 22 || $esi->size() < 100)) {
    //Log::log("populating tqApiESI: " . $esi->size());
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}
if ($esi->size() == 0) exit();

$bumped = [];
$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($esiCorps->pending() > 100) sleep(1);
    $charID = $esi->next(false);
    if ($charID > 0) {
        if ($redis->get("esi-fetched:$charID") == "true") { usleep(100000); continue; }

        $row = $mdb->findDoc("scopes", ['characterID' => (int) $charID, 'scope' => "esi-killmails.read_killmails.v1", "oauth2" => true], ['lastFetch' => 1]);
        if ($row != null) {
            $corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
            $row['corporationID'] = $corpID;
            if ($corpID !== @$row['corporationID']) {
                $mdb->set("scopes", $row, ['corporationID' => $corpID], true);
            }

            $hasRecent = $mdb->exists("ninetyDays", ['involved.characterID' => $charID]);
            if (!$hasRecent && @$row['lastFetch']->sec != 0 && (($charID % 24) != date('H'))) continue;

            $params = ['row' => $row, 'esi' => $esi];
            $refreshToken = $row['refreshToken'];
            $timer = new Timer();
            $accessToken = $sso->getAccessToken($refreshToken);
            $redis->rpush("timer:sso", round($timer->stop(), 0));
            if (is_array($accessToken) && @$accessToken['error'] == "invalid_grant") {
                $mdb->remove("scopes", $row);
                //sleep(1);
                continue;
            }

            $timer = new Timer();
            $killmails = $sso->doCall("$esiServer/v1/characters/$charID/killmails/recent/", [], $accessToken);
            success(['row' => $row, 'esi' => $esi, 'timer' => $timer], $killmails);
            $redis->setex("esi-fetched:$charID", 300, "true");
        } else {
            $esi->remove($charID);
        }
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
    $redis->rpush("timer:characters", round($timer->stop(), 0));

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        print_r($kills);
        return;
    }
    if (isset($kills['error'])) {
        // Something went wrong, reset it and try again later
        $esi->add($row['characterID'], 0);
        return;
    }
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash, '1.characters');
    }

    $charID = (int) $row['characterID'];
    $corpID = (int) $row['corporationID'];

    $modifiers = ['lastFetch' => $mdb->now(), 'errorCount' => 0];
    if (!isset($row['added']->sec)) $modifiers['added'] = $mdb->now();
    if (!isset($row['iterated'])) $modifiers['iterated'] = false;
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers); 
    $redis->setex("apiVerified:$charID", 86400, time());

    $mKillID = (int) $mdb->findField("killmails", "killID", ['involved.characterID' => $charID], ['killID' => -1]);
    if ($newKills == 0 && $mKillID < ($redis->get("zkb:topKillID") - 3000000) && @$row['iterated'] == true && isset($row['added']->sec)) {
        if ($row['added']->sec < (time() - (30 * 86400)) && $mKillID < ($redis->get("zkb:topKillID") - 10000000)) {
            $esi->remove($charID);
            $mdb->remove("scopes", $row);
            $redis->del("apiVerified:$charID");
            Util::out("Removed char killmail scope for $charID for inactivity");
            return;
        }
        // Otherwise check them roughly once a day
        $esi->setTime($charID, time() + (rand(24, 30) * 3600));
    }

    if ($newKills > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        if ($name === null) $name = $charID;
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        Util::out("$newKills kills added by char $name / $corpName", $charID);
    }

    // Check recently active characters every 5 minutes
    if ($redis->get("recentKillmailActivity:char:$charID") == "true") $esi->setTime($charID, time() + 301);
}
