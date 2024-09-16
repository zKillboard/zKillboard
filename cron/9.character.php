<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:universeLoaded") != "true") exit("Universe not yet loaded...\n");

$removeFields = ['corporationID', 'allianceID', 'factionID', 'secStatus', 'security_status', 'corporation_id', 'alliance_id', 'faction_id', 'title', 'gender', 'race_id', 'birthday', 'ancestry_id', 'bloodline_id'];

$currentSecond = "";
$guzzler = new Guzzler(5);
$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:reinforced") == true) break;
    $row = $mdb->findDoc("information", ['type' => 'characterID'], ['lastApiUpdate' => 1]);
    if ($row == null) {
        $guzzler->sleep(1);
        continue;
    }
    if (isset($row['lastApiUpdate']) && @$row['lastApiUpdate']->sec > (time() - (7 * 86400))) {
        $guzzler->sleep(1);
        continue;
    }
    $currentSecond = date('His');
    $id = (int) $row['id'];
    if ($id == 1) {
        // damnit
        $mdb->remove("information", $row);
        continue;
    }

    if (isset($row['lastApiUpdate'])) {
        $hasRecent = $mdb->exists("ninetyDays", ['involved.characterID' => $id]);
        if ($id <= 1 || (!$hasRecent && $redis->get("apiVerified:$id") == null)) { // don't clear api verified characters
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(), 'corporationID' => 0, 'allianceID' => 0, 'factionID' => 0]);        
            foreach ($removeFields as $field) if (isset($row[$field])) $mdb->removeField("information", $row, $field);
            continue;
        }
    }

    // doomheimed characters now throw 404's....
    // however, if a human manages to get their character brought back to life and log in with it,
    // we should be able to fetch that character's information again, so don't skip them
    if (isset($row['lastApiUpdate']) && (@$row['corporationID'] == 1000001 || $id <= 9999999)) {
        $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);
        continue;
    }


    $url = "$esiServer/v5/characters/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateChar", "failChar", $params, []);
    $guzzler->finish();
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
        case 400: // who knows what's ccp doing here
            Util::out("ERROR $id");
            $guzzler->sleep(1);
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(-23 * 3600)]); // try again in an hour
            break;
        case 404: // not deleting it...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * 14)]);
            $guzzler->sleep(1);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/v5/characters/ failed for $id with code $code");
    }
}

function updateChar(&$guzzler, &$params, &$content)
{
    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $id = (int) $row['id'];

    if ($content == "") {
        $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);
        return;
    }

    $content = Util::eliminateBetween($content, '"description"', '"faction_id"');
    $content = Util::eliminateBetween($content, '"description"', '"gender"');

    $json = json_decode($content, true);
    if (@$json['name'] == "") {
        $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);
        return; // bad data, ignore it
    }
    if (json_last_error() != 0) {
        Util::out("Character $id JSON issue: " . json_last_error() . " " . json_last_error_msg());
        return;
    }

    $corpID = (int) $json['corporation_id'];

    $updates = $json;
    if (@$row['obscene'] == true) {
        compareAttributes($updates, "name", @$row['name'], "Character " . $row['id']);
        compareAttributes($updates, "obscene_name", @$row['name'], (string) $json['name']);
    } else if (@$row['name'] == "") {
        compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    }
    compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    if (@$row['name'] != "") unset($updates['name']); // Names will no longer be updated here

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
