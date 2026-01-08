<?php


use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();
if ($kvc->get("zkb:noapi") == "true") exit();

$sso = ZKillSSO::getSSO();
$esiCorps = new RedisTimeQueue('tqCorpApiESI', $esiCorpKm);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $noCorp = $mdb->find("scopes", ['corporationID' => ['$exists' => false], 'scope' => "esi-killmails.read_corporation_killmails.v1"]);
    if (sizeof($noCorp) == 0) $noCorp = $mdb->find("scopes", ['corporationID' => 0, 'scope' => "esi-killmails.read_corporation_killmails.v1"]);
    foreach ($noCorp as $row) {
        $charID = $row['characterID'];
        $corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
        if ($corpID > 0) $mdb->set("scopes", $row, ['corporationID' => $corpID, 'lastFetch' => 0]);
        else {
            try {
                $mdb->insert("information", ['type' => 'characterID', 'id' => ((int) $charID), 'lastApiUpdate' => 0]);
            } catch (Exception $exx) { }
            sleep(1);
            continue;
        }
    }

    $mdb->set("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'lastFetch' => ['$exists' => false]], ['lastFetch' => 0], true);
    $row = $mdb->findDoc("scopes", ['corporationID' => ['$exists' => true], 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);

    if ($row == null) {
        sleep(3);
        continue;
    }
    $charID = ((int) $row['characterID']);
    $corpID = ((int) $row['corporationID']);

    if ($corpID <= 1999999) {
        $mdb->set("scopes", $row, ['lastFetch' => $mdb->now()]);
        continue;
    }

	$iterationKey = "zkb:corp:iterated:$corpID";
    $iterated = $redis->get($iterationKey);

	if ($iterated === "true") {
		sleep(1);
		continue;
	}

    $refreshToken = $row['refreshToken'];
    $accessToken = $sso->getAccessToken($refreshToken);
    if (is_array($accessToken)) {
        if ($accessToken['error'] == "invalid_grant") {
            $mdb->remove("scopes", $row);
            continue;
        }
    }

    // Update lastFetch immediately to prevent re-processing if something fails
    $mdb->set("scopes", $row, ['lastFetch' => $mdb->now()]);
    $page = 0;
    do {
        $page++;
		
        $uri = "$esiServer/corporations/$corpID/killmails/recent/?page=$page";
        $killmails = $sso->doCall($uri, [], $accessToken);
		
        $count = success(['row' => $row], $killmails, $uri);
        sleep(2);
        
        // Safety check: stop if count is not a valid number or exceeds reasonable limit
        if (!is_int($count) || $page > 100) break;
    } while ($count >= 1000);

	// lastFetch already updated above
	$redis->setex("esi-fetched:$corpID", 300, "true");
	$redis->setex($iterationKey, 604800, "true");

	if ($corpID > 1999999) {
		$esiCorps->add(((int) $corpID));
	}
}

function success($params, $content, $uri) 
{
    global $mdb, $redis;

    $count = 0;

    $row = $params['row'];
    $charID = $row['characterID'];
    $corpID = $row['corporationID'];
    $delay = (int) @$row['delay'];

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        print_r($kills);
        return 0; // Return 0 to stop pagination
    }
    if (isset($kills['error'])) {
        switch ($kills['error']) {
            case 'invalid_grant':
            case 'Character does not have required role(s)':
                $mdb->remove("scopes", $row);
                sleep(1);
                return -1;

            case 'Character is not in the corporation':
                $mdb->set("information", ['type' => 'characterID', 'id' => ((int) $charID)], ['lastApiUpdate' => 1, 'lastAffUpdate' => 1]);
                sleep(1);
                return 0; // Try again after the corp has been updated

            case "This software has exceeded the error limit for ESI. If you are a user, please contact the maintainer of this software. If you are a developer/maintainer, please make a greater effort in the future to receive valid responses. For tips on how, come have a chat with us in #esi on tweetfleet slack. If you're not on tweetfleet slack yet, you can get an invite here -> https://www.fuzzwork.co.uk/tweetfleet-slack-invites/":
                // 420'ed
                $mdb->set("scopes", $row, ['lastFetch' => $mdb->now()]);
                exit();
            case "Timeout contacting tranquility":
            case "Timeout waiting on backend":
            case "The datasource tranquility is temporarily unavailable":
                return -1;
            case "Undefined 404 response. Original message: Requested page does not exist!":
                return 0; // not really an error, just ignore it and move on
            default:
                Util::out("Unknown error: $uri\n" . print_r($kills, true));
                return 0;
        }
        // Something went wrong, reset it and try again later
        exit();
    }
    foreach ($kills as $kill) {
        $count++;
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash, '1.corp-iteration', $delay);
    }

    if ($newKills > 0) {
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        ZLog::add("$newKills kills added by corp $corpName *"  . ($delay == 0 ? '' : "($delay)"), $charID);
    }
    return $count;
}
