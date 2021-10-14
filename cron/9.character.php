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
    if (isset($row['lastApiUpdate']) && $row['lastApiUpdate']->sec > (time() - 86400)) {
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
        if ($id <= 1 || !$hasRecent) {
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(), 'corporationID' => 0, 'allianceID' => 0, 'factionID' => 0]);        
            foreach ($removeFields as $field) if (isset($row[$field])) $mdb->removeField("information", $row, $field);
            continue;
        }
    } else Util::out("Updating " . (isset($row['name']) ? $row['name'] : 'character ' . $id));
    if (isset($row['lastApiUpdate'])) while ($currentSecond == date('His')) $guzzler->sleep(0, 50);
    //Util::out($row['name'] . " " . $row['id']);

    $url = "$esiServer/v5/characters/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    //$a = (isset($row['lastApiUpdate']) && $row['name'] != '') ? ['etag' => true] : [];
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
    Util::out("ERROR $id");

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 504: // gateway timeout
        case 200: // timeout...
            $guzzler->sleep(1);
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(-23 * 3600)]);
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
