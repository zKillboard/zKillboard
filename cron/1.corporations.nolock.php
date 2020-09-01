<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

$guzzler = new Guzzler($esiCorpKillmails, 5);

$esi = new RedisTimeQueue('tqCorpApiESI', 3600);
if (date('i') == 22 || $esi->size() < 100) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
    foreach ($esis as $row) {
        if (@$row['characterID'] > 1 && @$row['corporationID'] > 1999999) $esi->add($row['corporationID']);
    }
}

$noCorp = $mdb->find("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'corporationID' => ['$exists' => false]]);
$chars = new RedisTimeQueue("zkb:characterID", 86400);
foreach ($noCorp as $row) {
    $charID = $row['characterID'];
    $corpID = (int) Info::getInfoField("characterID", $charID, "corporationID");
    if ($corpID > 0) $mdb->set("scopes", $row, ['corporationID' => $corpID]);
    else {
        $chars->add($charID);
        $chars->setTime($charID, 0);
    }
    if ($corpID > 1999999) $esi->add($corpID);
}


$mdb->set("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'lastFetch' => ['$exists' => false]], ['lastFetch' => 0], true);

$minute = date('Hi');
while ($minute == date('Hi')) {
    $corpID = (int) $esi->next();
    if ($corpID > 0) {
        $row = $mdb->findDoc("scopes", ['corporationID' => $corpID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row != null) {
            $refreshToken = $row['refreshToken'];
            $params = ['row' => $row, 'esi' => $esi, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'corpID' => $corpID];

            CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
        } else {
            $esi->remove($corpID);
        }
    } else {
        $guzzler->sleep(1);
    }
}
$guzzler->finish();


function accessTokenDone(&$guzzler, &$params, $content)
{
    global $ccpClientID, $ccpSecret, $esiServer;

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    $params['content'] = $content;
    $row = $params['row'];

    $headers = [];
    $headers['Content-Type'] = 'application/json';
    $headers['Authorization'] = "Bearer $accessToken";
    $headers['etag'] = true;

    $charID = $row['characterID'];
    $corpID = $params['corpID'];
    if (((int) $corpID) == 0) {
        Util::out("bad data\n" . print_r($row, true));
        return;
    }

    $url = "$esiServer/v1/corporations/$corpID/killmails/recent/";
    $guzzler->call($url, "success", "fail", $params, $headers, 'GET');
}

function success($guzzler, $params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $newKills += addMail($killID, $hash);
    }

    $charID = $row['characterID'];
    $corpID = $params['corpID'];

    $successes = 1 + ((int) @$row['successes']);
    $modifiers = ['corporationID' => $corpID, 'lastFetch' => $mdb->now(), 'successes' => $successes];
    if (!isset($row['added'])) $modifiers['added'] = $mdb->now();
    if (!isset($row['iterated'])) $modifiers['iterated'] = false;
    if ($content != "" && sizeof($kills) > 0) $modifiers['last_has_data'] = $mdb->now();
    $mdb->set("scopes", $row, $modifiers);

    $name = Info::getInfoField('characterID', $charID, 'name');
    $corpName = Info::getInfoField('corporationID', $corpID, 'name');
    if ($corpName == "") $corpName = "Corporation $corpID";
    $verifiedKey = "apiVerified:$corpID";
    $corpVerified = $redis->get($verifiedKey);
    if ($corpVerified === false) {
        ZLog::add("$corpName ($name) is now verified.", $charID);
    }
    $redis->setex($verifiedKey, 86400, time());
    $redis->del("zkb:corpInProgress:$corpID");

    if ($newKills > 0) {
        if ($name === null) $name = $charID;
        while (strlen("$newKills") < 3) $newKills = " " . $newKills;
        ZLog::add("$newKills kills added by corp $corpName", $charID);
        if ($newKills >= 10) User::sendMessage("$newKills kills added for corp $corpName", $charID);
    }

    $mKillID = (int) $mdb->findField("killmails", "killID", ['involved.corporationID' => $corpID], ['killID' => -1]);
    if ($newKills == 0 && $mKillID < ($redis->get("zkb:topKillID") - 10000000) && @$row['iterated'] == true && isset($row['added']->sec)) {
        if ($row['added']->sec < (time() - (180 * 86400)) && $mKillID < ($redis->get("zkb:topKillID") - 30000000)) {
            $esi->remove($charID);
            $mdb->remove("scopes", $row);
            $redis->del("apiVerified:$charID");
            Util::out("Removed corp killmail scope for $corpID / $corpName for inactivity ($mKillID)");
            return;
        }
    }

    $headers = $guzzler->getLastHeaders();
    if ($redis->get("recentKillmailActivity:$corpID") == "true") {
        $headers = $guzzler->getLastHeaders();
        $expires = $headers['expires'][0];
        $time = strtotime($expires);
        $esi->setTime($charID, $time + 2);
    }
}

function addMail($killID, $hash) 
{
    global $mdb, $redis;

    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
    if (!$exists) {
        try {
            //$mdb->getCollection('crestmails')->insert(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false]);
            $mdb->save('crestmails', ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
            return 1;
        } catch (Exception $ex) {
            if ($ex->getCode() != 11000) echo "$killID $hash : " . $ex->getCode() . " " . $ex->getMessage() . "\n";
        }
    }
    return 0;
}

function fail($guzzer, $params, $ex) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;
    $corpID = Info::getInfoField('characterID', (int) $charID, 'corporationID');

    if ($code == 403 || @$json['error'] == 'invalid_grant' || @$json['error'] == "Character does not have required role(s)" || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", $row);
        $esi->remove($charID);
        return;
    }
    if (@$json['error'] == "Character is not in the corporation") {
        $chars = new RedisTimeQueue("zkb:characterID", 86400);
        if ($chars->isMember($charID) == false) $chars->add($charID);
        $chars->setTime($charID, 1);
        return;
    }

    switch ($code) {
        case 403:
                $mdb->remove("scopes", $row);
                $esi->remove($charID);
            break;
        case 420:
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 503: // gateway timeout
        case 504:
        case "": // typically a curl timeout error
            //Util::out("corp killmail: " . $ex->getMessage() . "\n" . $params['content']);
            $esi->setTime($charID, time() + 30);
            break;
        default:
            Util::out("corp killmail: " . $ex->getMessage() . "\n" . $params['content']);
    }
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;

    if (@$json['error'] == 'invalid_grant' || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", $row);
        $esi->remove($charID);
        return;
    }

    switch ($code) {
        case 403: // A 403 without an invalid_grant is invalid
            $mdb->remove("scopes", $row);
            break;
        case 500:
        case 502: // Server error, try again in 5 minutes
        case "": // typically a curl timeout error
            $esi->setTime($charID, time() + 30);
            //            break;
        default:
            Util::out("corp token: $charID " . $ex->getMessage() . "\n" . $params['content'] . "\n" . "code $code");
    }
}
