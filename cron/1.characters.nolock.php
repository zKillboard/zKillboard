<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = ZKillSSO::getSSO();

if ($kvc->get("zkb:noapi") == "true") exit();

$bumped = [];
$minute = date('Hi');
$time = time() + 63;
$second = -1;
while ($time >= time()) {
    try {
	if ($mt == 0 && date('s') != $second) {
		$second = date('s');

		$mdb->set('scopes',
			[
				'scope' => 'esi-killmails.read_killmails.v1',
				'nextCheck' => ['$exists' => false]
			], [
				'nextCheck' => 0
			],
			true
		);

		$pending = $mdb->getCollection('scopes')->countDocuments(['scope' => 'esi-killmails.read_killmails.v1', 'nextCheck' => ['$lte' => time()]]);
		$total = $mdb->getCollection('scopes')->countDocuments(['scope' => 'esi-killmails.read_killmails.v1']);
		$redis->set('zkb:charKillmailScopesPending', "$pending");
		$redis->set('zkb:charKillmailScopesTotal', "$total");
	}
    if ($mt >= 5 && $redis->get("zkb:charKillmailScopesPending") < 150) break;
	$row = $mdb->getCollection('scopes')->findOneAndUpdate(
		[
			'scope' => 'esi-killmails.read_killmails.v1',
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

    $charID = (int) @$row['characterID'];
    if ($charID > 0) {
        if ($redis->set("esi-fetched:$charID", 'true', ['nx', 'ex' => 300]) === false)	
			continue;

		$corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
		$row['corporationID'] = $corpID;
		if ($corpID !== @$row['corporationID']) {
			$mdb->set("scopes", $row, ['corporationID' => $corpID]);
		}

        if ($corpID == 1000001) {
            // Player has been recycled....
            $this->getCollection("scopes")->deleteMany(['characterID' => $charID]);
            continue;
        }

		$params = ['row' => $row];
		$refreshToken = $row['refreshToken'];
		$timer = new Timer();
		$accessToken = $sso->getAccessToken($refreshToken);
		$redis->rpush("timer:sso", round($timer->stop(), 0));
		if (is_array($accessToken) && @$accessToken['error'] == "invalid_grant") {
            $mdb->getCollection("scopes")->deleteMany(['characterID' => $charID]);
			continue;
		}

		$timer = new Timer();
		$killmails = $sso->doCall("$esiServer/characters/$charID/killmails/recent/", [], $accessToken);
		success(['row' => $row, 'timer' => $timer], $killmails);

        $sleepMicroS = min(50000, max(1, 50000 - floor($timer->stop() * 1000)));
        usleep($sleepMicroS);
    } else {
        sleep(1);
    }
    } catch (Exception $ex) {
        Util::out(__FILE__ . " error: " . $ex->getMessage());
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
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
                Util::out("invalid token...");
                //$mdb->remove("scopes", $row);
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
    if (!isset($row['added']) || !($row['added'] instanceof MongoDB\BSON\UTCDateTime)) $modifiers['added'] = $mdb->now();
    if (!isset($row['iterated'])) $modifiers['iterated'] = false;
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers); 
    $redis->setex("apiVerified:$charID", 86400, time());

    if ($newKills > 0) {
        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        if ($name === null) $name = $charID;
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        Util::out("$newKills kills added by char $name / $corpName"  . ($delay == 0 ? '' : " (Delay: $delay)"), $charID);
    }

    // Check recently active characters every 5 minutes
    if ($redis->get("recentKillmailActivity:char:$charID") != "true") {
        $latest = $mdb->findDoc("killmails", ['involved.characterID' => $charID], ['killID' => -1], ['killID' => 1, 'dttm' => 1]);
        $time = $latest == null ? 0 : $latest['dttm']->toDateTime()->getTimestamp();
        $weekAgo = time() - (7 * 86400);
        $monthAgo = time() - (30 * 86400);
        $monthsAgo = time() - (90 * 86400);
        $yearAgo = time() - (365 * 86400);
        $adjustment = 0;
        if ($time < $yearAgo) $adjustment = 72;
        else if ($time < $monthsAgo) $adjustment = 24;
        else if ($time < $monthAgo) $adjustment = 4;
        else if ($time < $weekAgo) $adjustment = 1;

		$nextCheck = -1;
        if ($adjustment > 0) {
            $variance = (3600 * $adjustment) / 12;
            $nextCheck = time() + (3600 * $adjustment) + random_int(-1 * $variance, $variance);
        }
		$set = ['adjustment' => $adjustment];
		if ($nextCheck > 0) $set['nextCheck'] = $nextCheck;
        $mdb->set("scopes", $row, $set);
    }
}

