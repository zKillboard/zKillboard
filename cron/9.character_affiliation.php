<?php

require_once '../init.php';

if ($redis->get("zkb:noapi") == "true") exit();
if ($kvc->get("zkb:universeLoaded") != "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

$removeFields = ['corporationID', 'allianceID', 'factionID', 'secStatus', 'security_status', 'corporation_id', 'alliance_id', 'faction_id', 'title', 'gender', 'race_id', 'birthday', 'ancestry_id', 'bloodline_id'];


$currentSecond = "";
$guzzler = new Guzzler(5);
$minute = date('Hi');
while ($minute == date('Hi')) {
    $mdb->set("information", ['type' => 'characterID', 'corporationID' => ['$exists' => false]], ['corporationID' => 0, 'lastAffUpdate' => 0], true);
    $t = new Timer();
    if ($redis->get("zkb:reinforced") == true) break;
    $fetch = [];

    $result = $mdb->find("information", ['type' => 'characterID'], ['lastAffUpdate' => 1], 1000);
    foreach ($result as $row) {
        if (isset($row['lastAffUpdate']) && $row['lastAffUpdate'] instanceof MongoDB\BSON\UTCDateTime && $row['lastAffUpdate']->toDateTime()->getTimestamp() > (time() - 86400)) {
            usleep(1000);
            continue;
        }
        $currentSecond = date('His');
        $id = (int) $row['id'];
        if ($id == 1) {
            $mdb->set("information", $row, ['lastAffUpdate' => $mdb->now()]);
            continue;
        }

        // doomheimed characters now throw 404's....
        // however, if a human manages to get their character brought back to life and log in with it,
        // we should be able to fetch that character's information again, so don't skip them
/*        if (isset($row['lastAffUpdate']) && (@$row['corporationID'] == 1000001 || $id <= 999999)) {
            $mdb->set("information", $row, ['lastAffUpdate' => $mdb->now()]);
            continue;
        }*/
        $fetch[] = $id;
    }

    if (sizeof($fetch) == 0) {
        $guzzler->sleep(1);
        continue;
    }

    $url = "$esiServer/characters/affiliation/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'chars' => $fetch];
    $guzzler->call($url, "updateChar", "failChar", $params, [], 'POST_JSON', json_encode($fetch, true));
    $guzzler->finish();
    while ($t->stop() < 1000) usleep(100000);
    sleep(1);
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];
    $chars = $params['chars'];

    foreach ($chars as $char) {
        $row = ['type' => 'characterID', 'id' => $char];
        switch ($code) {
            case 0: // timeout
            case 500:
            case 502: // ccp broke something...
            case 503: // server error
            case 504: // gateway timeout
            case 200: // timeout...
            case 400: // ccp up to something again
                $mdb->set("information", $row, ['lastAffUpdate' => $mdb->now(rand(-86400, -40000))]);
                break;
            case 404: // not deleting it...
                Util::out("ERROR $id $code");
                $mdb->set("information", $row, ['lastAffUpdate' => $mdb->now(86400 * rand(14, 28))]);
                break;
            case 420:
                Util::out("ERROR $id $code");
                $guzzler->finish();
                exit();
            default:
                Util::out("/characters/ affiliation failed for $id with code $code");
        }
    }
    $guzzler->sleep(1);
}

function updateChar(&$guzzler, &$params, &$content)
{

    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $id = (int) $row['id'];

    if ($content == "") {
        $mdb->set("information", $row, ['lastAffUpdate' => $mdb->now()]);
        return;
    }

    $json = json_decode($content, true);
    if (json_last_error() != 0) {
        Util::out("Character $id JSON issue: " . json_last_error() . " " . json_last_error_msg());
        return;
    }

    foreach ($json as $row) {
        $id = (int) $row['character_id'];
        $corpID = (int) $row['corporation_id'];
        $alliID = (int) @$row['alliance_id'];
        $factionID = (int) @$row['faction_id'];

        $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
        if ($corpExists == 0) {
            $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
        }
        if ($alliID > 0) {
            $alliExists = $mdb->count("information", ['type' => 'allianceID', 'id' => $alliID]);
            if ($alliExists == 0) {
                $mdb->insertUpdate("information", ['type' => 'allianceID', 'id' => $alliID]);
            }
        }

        $mdb->set("information", ['type' => 'characterID', 'id' => $id], ['corporationID' => $corpID, 'allianceID' => $alliID, 'factionID' => $factionID, 'corporation_id' => $corpID, 'alliance_id' => $alliID, 'faction_id' => $factionID, 'lastAffUpdate' => $mdb->now()]);
        $redis->del(Info::getRedisKey('characterID', $id));
        // Make sure the scopes have the right corporationID for this character
        $mdb->set("scopes", ['characterID' => $id], ['corporationID' => $corpID], true);
    }
}
