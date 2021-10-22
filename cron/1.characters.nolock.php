<?php

pcntl_fork();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = EveOnlineSSO::getSSO();

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:noapi") == "true") exit();

$esi = new RedisTimeQueue('tqApiESI', 3600);
$esiCorp = new RedisTimeQueue('tqCorpApiESI', 3600);
if (date('i') == 22 || $esi->size() < 100) {
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
    $charID = $esi->next(false);
    if ($charID > 0) {
        $row = $mdb->findDoc("scopes", ['characterID' => (int) $charID, 'scope' => "esi-killmails.read_killmails.v1", "oauth2" => true], ['lastFetch' => 1]);
        if ($row != null) {
            $corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
            $row['corporationID'] = $corpID;
            if ($corpID !== @$row['corporationID']) {
                $mdb->set("scopes", $row, ['corporationID' => $corpID]);
            }
            if (@$row['iterated'] == true && $redis->get("apiVerified:$corpID") != null && $redis->ttl("apiVerified:$charID") > 7200 ) {
                $lastChecked = $redis->get("apiVerified:$corpID");
                if ($lastChecked > 0 && (time() - $lastChecked) > 300 && !in_array($corpID, $bumped)) {
                    $esiCorp->setTime($corpID, 1);
                    $bumped[] = $corpID;
                }
                continue;
            }

            $hasRecent = $mdb->exists("ninetyDays", ['involved.characterID' => $charID]);
            if (!$hasRecent && @$row['lastFetch']->sec != 0 && (($charID % 24) != date('H'))) continue;

            // Give corporation checks priority
            if ($esiCorp->pending() > $ssoThrottle) sleep(1);

            $params = ['row' => $row, 'esi' => $esi];
            $refreshToken = $row['refreshToken'];
            $accessToken = $redis->get("oauth2:$charID:$refreshToken");
            if ($accessToken == null) {
                $accessToken = $sso->getAccessToken($refreshToken);
                if (isset($accessToken['error'])) {
                    $mdb->remove("scopes", $row);
                    sleep(1);
                    continue;
                }
                $redis->setex("oauth2:$charID:$refreshToken", 900, $accessToken);
            }
            $killmails = $sso->doCall("$esiServer/v1/characters/$charID/killmails/recent/", [], $accessToken);
            success(['row' => $row, 'esi' => $esi], $killmails);
        } else {
            $esi->remove($charID);
        }
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
        $esi->add($row['characterID'], 0);
        return;
    }
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += addMail($killID, $hash);
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
    // Check recently active characters every 5 minutes
    if ($redis->get("recentKillmailActivity:$charID") == "true") {
        $esi->setTime($charID, time() + 310);
    }

    if ($newKills > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        if ($name === null) $name = $charID;
        while (strlen("$newKills") < 3) $newKills = " " . $newKills;
        ZLog::add("$newKills kills added by char $name / $corpName", $charID);
        if ($newKills >= 10) User::sendMessage("$newKills kills added for char $name", $charID);
    }
}

function addMail($killID, $hash) 
{
    global $mdb, $redis;

    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
    if (!$exists) {
        try {
            $mdb->save('crestmails', ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
            return 1;
        } catch (MongoDuplicateKeyException $ex) {
            // ignore it *sigh*
        }
    }
    return 0;
}
