<?php


use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();
if ($kvc->get("zkb:noapi") == "true") exit();

$sso = ZKillSSO::getSSO();

$minute = date('Hi');

$mdb->getCollection("scopes")->updateMany(['iterated' => 'wait'], ['$set' => ['iterated' => false]]);
$mdb->getCollection("scopes")->updateMany(['iterated' => 'error'], ['$set' => ['iterated' => false]]);

while ($minute == date('Hi')) {
    $row = $mdb->findDoc("scopes", ['scope' => "esi-killmails.read_killmails.v1", 'iterated' => false]);

    if ($row == null) {
        sleep(3);
        continue;
    }
    $charID = ((int) $row['characterID']);

    $refreshToken = $row['refreshToken'];
    $accessToken = $sso->getAccessToken($refreshToken);
    if (is_array($accessToken)) {
        if ($accessToken['error'] == "invalid_grant") {
            $mdb->remove("scopes", $row);
            continue;
        }
    }

    $charKey = "zkb:char-iterate:$charID";
    if ($redis->set($charKey, "true", ['nx', 'ex' => 9999]) === false) {
        $mdb->set("scopes", $row, ['iterated' => 'wait']);
        continue;
    }
    try {
        // Update lastFetch immediately to prevent re-processing if something fails
        $mdb->set("scopes", $row, ['lastFetch' => $mdb->now()]);
        $page = 0;
        do {
            $page++;

            $uri = "$esiServer/characters/$charID/killmails/recent/?page=$page";
            //Util::out("$uri $charID");
            $killmails = $sso->doCall($uri, [], $accessToken);

            $count = success(['row' => $row], $killmails, $uri);
            global $resHeaders;
            if (((int) $resHeaders['x-ratelimit-remaining']) < 10) {
                //Util::out("x-ratelimit-remaining low, sleeping");
                sleep(300);
                $accessToken = $sso->getAccessToken($refreshToken);
            }

            // Safety check: stop if count is not a valid number or exceeds reasonable limit
            if (!is_int($count) || $page > 50) break;
        } while ($count >= 1000);

        $mdb->set("scopes", $row, ['iterated' => true]);
    } catch (Exception $ex) {
        $mdb->set("scopes", $row, ['iterated' => 'error']);
    } finally {
        // don't re-iterate a char until this has expired
        $redis->expire($charKey, 9);
    }
}

function success($params, $content, $uri) 
{
    global $mdb, $redis;

    $count = 0;

    $row = $params['row'];
    $charID = $row['characterID'];
    $charID = $row['characterID'];
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
                return -1;
            case 'Requested page does not exist!':
            case "Undefined 404 response. Original message: Requested page does not exist!":
                                                            return 0; // not really an error, just ignore it and move on
            case "Timeout contacting tranquility":
            case "Timeout waiting on backend":
            case "The datasource tranquility is temporarily unavailable":
            default:
                                                            Util::out("Unknown error: $uri\n" . print_r($kills, true));
                                                            throw new Exception();
        }
        // Something bigger went wrong, reset it and try again later
        throw new Exception();
    }
    foreach ($kills as $kill) {
        $count++;
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += Killmail::addMail($killID, $hash, '1.char-iteration', $delay);
    }

    if ($newKills > 0) {
        $charName = Info::getInfoField('characterID', $charID, 'name');
        $newKills = str_pad($newKills, 3, " ", STR_PAD_LEFT);
        ZLog::add("$newKills kills added by char $charName *"  . ($delay == 0 ? '' : "($delay)"), $charID);
    }
    return $count;
}
