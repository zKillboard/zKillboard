<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();
$guzzler = new Guzzler(20, 1000);

$esi = new RedisTimeQueue('tqCorpApiESI', 3601);
if (date('i') == 22 || $esi->size() < 100) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_corporation_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::checkStatus($guzzler, 'esi');
    Status::checkStatus($guzzler, 'sso');
    Status::throttle('sso', 20);
    $charID = (int) $esi->next();
    $corpID = Info::getInfoField('characterID', $charID, 'corporationID');
    if ($charID > 0 && $corpID > 0) {
        $alliID = Info::getInfoField('characterID', $charID, 'allianceID');
        if (in_array($corpID, $ignoreEntities) || in_array($alliID, $ignoreEntities)) continue;

        $row = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['lastFetch' => 1]);
        if ($row != null) {
            $params = ['row' => $row, 'esi' => $esi];
            $refreshToken = $row['refreshToken'];

            CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
        } else {
            $esi->remove($charID);
        }
    }
    $guzzler->tick();
}
$guzzler->finish();


function accessTokenDone(&$guzzler, &$params, $content)
{
    global $ccpClientID, $ccpSecret;

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    $params['content'] = $content;
    $row = $params['row'];

    $headers = [];
    $headers[] = 'Content-Type: application/json';

    $charID = $row['characterID'];
    $corpID = Info::getInfoField("characterID", $charID, 'corporationID');
    $fields = ['token' => $accessToken];
    if (isset($params['max_kill_id'])) {
        $fields['max_kill_id'] = $params['max_kill_id'];
    }
    $fields = ESI::buildparams($fields);
    $url = "https://esi.tech.ccp.is/v1/corporations/$corpID/killmails/recent/?$fields";

    $guzzler->call($url, "success", "fail", $params, $headers, 'GET');
}

function success($guzzler, $params, $content) 
{
    global $mdb, $redis;

    $newKills = (int) @$params['newKills'];
    $maxKillID = (int) @$params['maxKillID'];
    $row = $params['row'];
    $prevMaxKillID = (int) @$row['maxKillID'];
    $minKillID = isset($params['max_kill_id']) ? $params['max_kill_id'] : 9999999999;
    $esi = $params['esi'];

    $kills = json_decode($content, true);
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $minKillID = min($killID, $minKillID);
        $maxKillID = max($killID, $maxKillID);

        $newKills += addMail($killID, $hash);
    }

    if (sizeof($kills) && $minKillID > $prevMaxKillID) {
        $params['newKills'] = $newKills;
        $params['max_kill_id'] = $minKillID;
        $params['maxKillID'] = $maxKillID;

        accessTokenDone($guzzler, $params, $params['content']);
    } else {
        $charID = $row['characterID'];

        $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
        $successes = 1 + ((int) @$row['successes']);
        $mdb->set("scopes", $row, ['maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now(), 'successes' => $successes]);

        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');
        $verifiedKey = "apiVerified:$corpID";
        $corpVerified = $redis->get($verifiedKey);
        if ($corpVerified === false) ZLog::add("$corpName ($name) is now verified.", $charID);
        $redis->setex($verifiedKey, 86400, time());

        if ($newKills > 0) {
            if ($name === null) $name = $charID;
            while (strlen("$newKills") < 3) $newKills = " " . $newKills;
            ZLog::add("$newKills kills added by corp $corpName", $charID);
            if ($newKills >= 10) User::sendMessage("$newKills kills added for corp $corpName", $charID);
        }
        if ($redis->get("recentKillmailActivity:$corpID") == "true") {
            $headers = $guzzler->getLastHeaders();
            $expires = $headers['Expires'];
            $time = strtotime($expires[0]);
            if ($expires > time()) $esi->setTime($charID, $time + 10);
        }
    }
}

function addMail($killID, $hash) 
{
    global $mdb;

    $exists = $mdb->exists('crestmails', ['killID' => $killID, 'hash' => $hash]);
    if (!$exists) {
        try {
            $mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'esi', 'added' => $mdb->now()]);
            return 1;
        } catch (MongoDuplicateKeyException $ex) {
            // ignore it *sigh*
        }
    }
    return 0;
}

function fail($guzzer, $params, $ex) 
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;

    if (@$json['error'] == 'invalid_grant' || @$json['error'] == "Character does not have required role(s)" || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", $row);
        $esi->remove($charID);
        return;
    }
    if (@$json['error'] == "Character is not in the corporation") {
        $mdb->removeField("scopes", $row, "corporationID");
        $mdb->removeField("information", ['type' => 'characterID', 'id' => $charID], "corporationID");
        $chars = new RedisTimeQueue("zkb:characterID", 86400);
        $chars->setTime($charID, 0);
        $esi->setTime($charID, time() - 3550);
        return;
    }

    switch ($code) {
        case 403:
        case 420:
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 503:
            $esi->setTime($charID, time() + 300);
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

    if (@$json['error'] == 'invalid_grant') {
        $mdb->remove("scopes", $row);
        $esi->remove($charID);
        return;
    }

    switch ($code) {
        case 403: // A 403 without an invalid_grant is invalid
        case 500:
        case 502: // Server error, try again in 5 minutes
            $esi->setTime($charID, time() + 300);
            break;
        default:
            Util::out("corp token: $charID " . $ex->getMessage() . "\n" . $params['content']);
    }
    sleep(1);
}
