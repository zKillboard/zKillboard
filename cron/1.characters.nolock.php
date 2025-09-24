<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$kvc = new KVCache($mdb, $redis);

$sso = ZKillSSO::getSSO();

if ($redis->get("zkb:noapi") == "true") exit();

$esiCorps = new RedisTimeQueue('tqCorpApiESI', 3600);
$esi = new RedisTimeQueue('tqApiESI', 900);
if ($mt == 0 && (date('i') == 22 || $esi->size() < 100)) {
    //Log::log("populating tqApiESI: " . $esi->size());
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}
if ($esi->size() == 0) exit();

$noCharCount = 0;
$bumped = [];
$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($esiCorps->pending() > 100) sleep(1);
    $charID = (int) $esi->next(false);
    if ($charID > 0) {
        if ($redis->get("esi-fetched:$charID") == "true") { usleep(100000); continue; }

        $row = $mdb->findDoc("scopes", ['characterID' => (int) $charID, 'scope' => "esi-killmails.read_killmails.v1", "oauth2" => true], ['lastFetch' => 1]);
        if ($row != null) {
            $corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
            $row['corporationID'] = $corpID;
            if ($corpID !== @$row['corporationID']) {
                $mdb->set("scopes", $row, ['corporationID' => $corpID], true);
            }

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
        $noCharCount++;
        if ($noCharCount > 10 && $mt > 10) break;
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];
    $timer = $params['timer'];
    $delay = (int) @$row['delay'];
    $redis->rpush("timer:characters", round($timer->stop(), 0));

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        print_r($kills);
        return;
    }
    if (isset($kills['error'])) {
        switch($kills['error']) {
            case "Unauthorized - Invalid token":
                $mdb->remove("scopes", $row);
                break;
            default:
                // Something went wrong, reset it and try again later
                Util::out("1.characters error - \n" . print_r($row, true) . "\n" . print_r($kills, true));
        }
        sleep(1);
        return;
    }

    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash, '1.characters', $delay);
    }

    $charID = (int) $row['characterID'];
    $corpID = (int) $row['corporationID'];

    $modifiers = ['lastFetch' => $mdb->now(), 'errorCount' => 0];
    if (!isset($row['added']->sec)) $modifiers['added'] = $mdb->now();
    if (!isset($row['iterated'])) $modifiers['iterated'] = false;
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers); 
    $redis->setex("apiVerified:$charID", 86400, time());

    if ($newKills > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        if ($name === null) $name = $charID;
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        Util::out("$newKills kills added by char $name / $corpName"  . ($delay == 0 ? '' : "($delay)"), $charID);
    }

    // Check recently active characters every 5 minutes
    if ($redis->get("recentKillmailActivity:char:$charID") == "true") $esi->setTime($charID, time() + 301);
    else {
        $latest = $mdb->findDoc("killmails", ['involved.characterID' => $charID], ['killID' => -1], ['killID' => 1, 'dttm' => 1]);
        $time = $latest == null ? 0 : $latest['dttm']->sec;
        $weekAgo = time() - (7 * 86400);
        $monthAgo = time() - (30 * 86400);
        $monthsAgo = time() - (90 * 86400);
        $yearAgo = time() - (365 * 86400);
        $adjustment = 0;
        if ($time < $yearAgo) $adjustment = 72;
        else if ($time < $monthsAgo) $adjustment = 24;
        else if ($time < $monthAgo) $adjustment = 4;
        else if ($time < $weekAgo) $adjustment = 1;
        if ($adjustment > 0) {
            $variance = (3600 * $adjustment) / 12;
            //Util::zout("$charID adjustment $adjustment $variance");
            $esi->setTime($charID, time() + (3600 * $adjustment) + random_int(-1 * $variance, $variance));
        }
        $mdb->set("scopes", $row, ['adjustment' => $adjustment]);
    }
}
