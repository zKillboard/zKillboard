<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

if ($redis->get("zkb:universeLoaded") != "true") exit("Universe not yet loaded...\n");

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:420prone") == "true") exit();
$guzzler = new Guzzler(5);
$chars = new RedisTimeQueue("zkb:characterID", 86400);
$maxKillID = $mdb->findField("killmails", "killID", [], ['killID' => -1]) - 5000000;

$oneYear = 365 * 86400; 
$dayOfYear = date("z");
$dayPrime = $dayOfYear % 73;

$mod = 3;
$dayMod = date("j") % $mod;
$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = (int) $chars->next();
    if ($id > 1) {
        $guzzler->tick();
        usleep(250000);
        $guzzler->tick();
        $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $id]);

        //if (((int) @$row['lastApiUpdate']->sec) != 0) continue;

        $killmail = $mdb->findDoc("killmails", ['involved.characterID' => $id], ['killID' => -1]);
        $epoch = ($killmail == null ? 0 : $killmail['dttm']->sec);

        // Only update characters that have had a kill in the last year, the rest update only 5 times a year
        if (((int) @$row['lastApiUpdate']->sec) != 0 && time() - $epoch > $oneYear && $id & 73 != $dayPrime) continue;

        $url = "$esiServer/v4/characters/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'rtq' => $chars];
        $a = (isset($row['lastApiUpdate']) && $row['name'] != '')? [] /*['etag' => true]*/ : [];
        $guzzler->call($url, "updateChar", "failChar", $params, $a);
    }
    if ($id <= 1) {
        $guzzler->tick();
        sleep(1);
    }
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];
    $rtq = $params['rtq'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
            sleep(1);
            $newTime = (time() - 86400) + rand(3600, 7200);
            //Util::out("Newtime for $id : " . (time() - $newTime)); 
            //$rtq->setTime($id, $newTime);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/v4/characters/ failed for $id with code $code");
    }
}

function updateChar(&$guzzler, &$params, &$content)
{
    if ($content == "") return;

    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $id = (int) $row['id'];

    $content = Util::eliminateBetween($content, '"description"', '"faction_id"');
    $content = Util::eliminateBetween($content, '"description"', '"gender"');

    $json = json_decode($content, true);
    if (json_last_error() != 0) {
        Util::out("Character $id JSON issue: " . json_last_error() . " " . json_last_error_msg());
        return;
    }

    $corpID = (int) $json['corporation_id'];

    $updates = $json;
    if (@$row['obscene'] == true) {
        compareAttributes($updates, "name", @$row['name'], "Character " . $row['id']);
        compareAttributes($updates, "obscene_name", @$row['name'], (string) $json['name']);
    } else compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "corporationID", @$row['corporationID'], $corpID);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) Info::getInfoField("corporationID", $corpID, 'allianceID'));
    compareAttributes($updates, "factionID", @$row['factionID'], 0);
    compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
    if ($corpExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
        $corps = new RedisTimeQueue("zkb:corporationID", 86400);
        $corps->add($corpID);
    }

    $updates['lastApiUpdate'] = $mdb->now();
    $mdb->set("information", $row, $updates);
    if (sizeof($updates) > 1) {
        $redis->del(Info::getRedisKey('characterID', $id));
    }
    // Make sure the scopes have the right corporationID for this character
    $mdb->set("scopes", ['characterID' => $id], ['corporationID' => $corpID], true);
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}

function ew_ignore($guzzler, $params, $content)
{
    if (strlen($content) > 0) Util::out($content);
}
