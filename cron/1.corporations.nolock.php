<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); 

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($mt > $esiCorpMaxThreads) exit();

$kvc = new KVCache($mdb, $redis);

$sso = ZKillSSO::getSSO();

if ($redis->get("zkb:noapi") == "true") exit();

$esi = new RedisTimeQueue('tqCorpApiESI', $esiCorpKm);

if ($mt == 0 && (date("i") == 44 || $esi->size() < 100)) {
       $corpIDs = $mdb->getCollection("scopes")->distinct("corporationID", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
       foreach ($corpIDs as $corpID) $esi->add($corpID);
}

$noCorpCount = 0;
$minute = date('Hi');
while ($minute == date('Hi')) {
    $corpIDRaw = $esi->next(); // This is RedisTimeQueue->next(), not MongoDB cursor
    $corpID = (int) $corpIDRaw;
    if ($corpID > 1999999) {
        if ($redis->get("esi-fetched:$corpID") == "true") continue;
        
        $row = $mdb->findDoc("scopes", ['corporationID' => $corpID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row == null) {
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

        $redis->setex("esi-fetched:$corpID", 300, "true");
        $timer = new Timer();
        $killmails = $sso->doCall("$esiServer/corporations/$corpID/killmails/recent/", [], $accessToken);
        success(['corpID' => $corpID, 'row' => $row, 'esi' => $esi, 'timer' => $timer], $killmails);
        usleep(100000);
    } else if ($corpID > 0) {
        // probably an npc corp, can ignore
    } else {
        $noCorpCount++;
        if ($noCorpCount > 10 && $mt > 10) break; // allows us to handle bursts 
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    //global $resHeaders;
    //Util::out(print_r($resHeaders, true));

    $row = $params['row'];
    $esi = $params['esi'];
    $timer = $params['timer'];
    $delay = (int) @$row['delay'];
    $redis->rpush("timer:corporations", round($timer->stop(), 0));

    $charID = $row['characterID'];
    $corpID = $params['corpID'];

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        Util::out("1.corporations invalid response for corp $corpID: " . substr($content, 0, 200));
        return;
    }
    if (isset($kills['error'])) {
        switch($kills['error']) {
            case "Character does not have required role(s)":
                $mdb->remove("scopes", $row);
                $redis->del("esi-fetched:" . $params['corpID']);
                $esi->add($row['corporationID'], 1); // try others, if we have them
                break;
            case "Unauthorized - Invalid token":
                $redis->del("esi-fetched:" . $params['corpID']);
                $esi->add($row['corporationID'], 35);
                break;
            case "Character is not in the corporation":
                $mdb->set("information", ['type' => 'characterID', 'id' => (int) $charID], ['lastAffUpdate' => $mdb->now(-86400 * 30)]);
                break;
            case "Timeout contacting tranquility":
                Util::out("1.corporations.php - Timeout contacting tranquility");
                break;
            default:
                // Something went wrong, reset it and try again later
                Util::out("1.corporations error - \n" . print_r($row, true) . "\n" . print_r($kills, true));
                $esi->add($row['corporationID'], 999);
        }
        sleep(1);
        return;
    }
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash, '1.corporation', $delay);
    }

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
        Util::out("$newKills kills added by corp $corpName" . ($delay == 0 ? '' : "($delay)"));
    }

    if ($redis->get("recentKillmailActivity:corp:$corpID") == "true") $esi->setTime($corpID, time() + 301);

    $latest = $mdb->findDoc("killmails", ['involved.corporationID' => $corpID], ['killID' => -1], ['killID' => 1, 'dttm' => 1]);
    $time = $latest == null ? 0 : $latest['dttm']->toDateTime()->getTimestamp();
    $threeDaysAgo = time() - (3 * 86400);
    $monthAgo = time() - (30 * 86400);
    $yearAgo = time() - (365 * 86400);
    $adjustment = 0;
    if ($time < $yearAgo) $adjustment = 24;
    else if ($time < $monthAgo) $adjustment = 1;
    else if ($time < $threeDaysAgo) $adjustment = 0.25;
    if ($adjustment > 0) {
        $variance = (3600 * $adjustment) / 12;
        $esi->setTime($corpID, time() + (3600 * $adjustment) + random_int(-1 * $variance, $variance));
    }
    $mdb->set("scopes", $row, ['adjustment' => $adjustment]);
}
