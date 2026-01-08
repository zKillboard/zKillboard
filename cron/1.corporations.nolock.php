<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); 

require_once "../init.php";

if ($mt > $esiCorpMaxThreads) exit();

$sso = ZKillSSO::getSSO();

if ($kvc->get("zkb:noapi") == "true") exit();

$noCorpCount = 0;
$minute = date('Hi');
$second = -1;
while ($minute == date('Hi')) {
	if ($mt == 0 && date("s") != $second) {
        $second = date("s");

        $mdb->set("scopes",
            [
                'scope' => 'esi-killmails.read_corporation_killmails.v1',
                'nextCheck' => ['$exists' => false]
            ], [
                'nextCheck' => 0
            ],
                true
            );

        $pending = $mdb->getCollection('scopes')->countDocuments(['scope' => 'esi-killmails.read_corporation_killmails.v1', 'nextCheck' => ['$lte' => time()]]);
        $total = $mdb->getCollection('scopes')->countDocuments(['scope' => 'esi-killmails.read_corporation_killmails.v1']);
        $redis->set("zkb:corpKillmailScopesPending", "$pending");
        $redis->set("zkb:corpKillmailScopesTotal", "$total");
    }
    $row = $mdb->getCollection("scopes")->findOneAndUpdate(
            [
            'scope' => 'esi-killmails.read_corporation_killmails.v1',
            'nextCheck' => ['$lte' => time()]
            ],
            [
            '$set' => ['nextCheck' => time() + 900 + mt_rand(-30, 30)]
            ],
            [
            'sort' => ['nextCheck' => 1],
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
            );

    $corpID = (int) @$row['corporationID'];
    if ($corpID > 1999999) {
        if ($redis->set("esi-fetched:$corpID", "true", ['nx', 'ex' => 300]) === false) continue;

        $charID = $row['characterID'];
        $refreshToken = $row['refreshToken'];
        $params = ['row' => $row, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'corpID' => $corpID];

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
        success(['mdb' => $mdb, 'corpID' => $corpID, 'row' => $row, 'timer' => $timer], $killmails);
        usleep(100000);
    } else if ($corpID > 0) {
        // npc corp, ignore it
        $mdb->set("scopes", $row, ['nextCheck' => (time() + mt_rand(3600, 7200))]);
    } else {
        $noCorpCount++;
        if ($noCorpCount > 10 && $mt > 3) break; // allows us to handle bursts 
        sleep(1);
    }
}

function success($params, $content) 
{
    $mdb = $params['mdb'];
    $row = $params['row'];
    $timer = $params['timer'];
    $delay = (int) @$row['delay'];

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
                break;
            case "Unauthorized - Invalid token":
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

    $latest = $mdb->findDoc("killmails", ['involved.corporationID' => $corpID], ['killID' => -1], ['killID' => 1, 'dttm' => 1]);
    $time = $latest == null ? 0 : $latest['dttm']->toDateTime()->getTimestamp();
    $threeDaysAgo = time() - (3 * 86400);
    $monthAgo = time() - (30 * 86400);
    $yearAgo = time() - (365 * 86400);
    $adjustment = 0;
    if ($time < $yearAgo) $adjustment = 24;
    else if ($time < $monthAgo) $adjustment = 1;
    else if ($time < $threeDaysAgo) $adjustment = 0.25;

    $nextCheck = -1;
    if ($adjustment > 0) {
        $variance = (3600 * $adjustment) / 12;
        $nextCheck = time() + (3600 * $adjustment) + random_int(-1 * $variance, $variance);		
    }
    $set = ['adjustment' => $adjustment];
    if ($nextCheck != -1) $set['nextCheck'] = $nextCheck;
    $mdb->set("scopes", ['scope' => 'esi-killmails.read_corporation_killmails.v1', 'corporationID' => $corpID], $set, true);
}

