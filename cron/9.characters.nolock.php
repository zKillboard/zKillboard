<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

if ($redis->get("zkb:universeLoaded") != "true") exit("Universe not yet loaded...\n");

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:420prone") == "true") exit();
$guzzler = new Guzzler();
$chars = new RedisTimeQueue("zkb:characterID", 86400);
$maxKillID = $mdb->findField("killmails", "killID", [], ['killID' => -1]) - 5000000;

$mod = 3;
$dayMod = date("j") % $mod;
$minute = date('Hi');
while ($minute == date('Hi')) {
    $id = (int) $chars->next();
    if ($id > 1) {
        $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $id]);
        /*if (strpos(@$row['name'], 'characterID') === false && isset($row['corporationID'])) {
            $charMaxKillID = (int) $mdb->findField("killmails", "killID", ['involved.characterID' => $id], ['killID' => -1]);
            if ($maxKillID > $charMaxKillID && ($id % $mod != $dayMod)) continue;
        }*/

        $url = "$esiServer/v4/characters/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'rtq' => $chars];
        $a = (isset($row['lastApiUpdate']) && $row['name'] != '')? ['etag' => true] : [];
        $guzzler->call($url, "updateChar", "failChar", $params, $a);
    }
    if ($id == 0) {
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
        case 500:
            // Do nothing, something wrong with the character on CCPs end
            break;
        case 0: // timeout
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
            $rtq->setTime($id, (time() - 86400) + rand(3600, 7200));
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

    $updates = [];
    if (@$row['obscene'] == true) {
        compareAttributes($updates, "name", @$row['name'], "Character " . $row['id']);
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
