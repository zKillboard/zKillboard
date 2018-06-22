<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

global $debug;

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:420prone") == "true") exit();

$guzzler = new Guzzler();

$minute = date('Hi');
while ($minute == date('Hi')) {
    $row = $mdb->findDoc("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'iterated' => false, 'corporationID' => ['$gt' => 1999999], 'successes' => ['$gt' => 0]], ['_id' => -1]);
    if ($row == null) break;

    $charID = $row['characterID'];
    $corpID = $row['corporationID'];
    $corpName = Info::getInfoField('corporationID', $corpID, 'name');
    if ($charID && $corpID > 1999999) {
        $refreshToken = $row['refreshToken'];
        $row['corporationID'] = $corpID;
        $params = ['row' => $row, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'page' => 1];
        $mdb->set("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'corporationID' => $corpID], ['iterated' => 'in progress'], true);

        CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
    }
    if ($charID == 0) {
        $guzzler->tick();
        sleep(1);
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

    $charID = $row['characterID'];
    $corpID = Info::getInfoField("characterID", $charID, 'corporationID');
    $fields = [];
    if (isset($params['page'])) {
        $fields['page'] = $params['page'];
    }
    $fields = ESI::buildparams($fields);
    if (strlen($fields)) $fields = "?$fields";
    $url = "$esiServer/v1/corporations/$corpID/killmails/recent/$fields";
    $guzzler->call($url, "success", "fail", $params, $headers, 'GET');
}

function success($guzzler, $params, $content) 
{
    global $mdb, $redis;

    $newKills = (int) @$params['newKills'];
    $maxKillID = (int) @$params['maxKillID'];
    $row = $params['row'];
    $prevMaxKillID = $mdb->findField("scopes", "maxKillID", ['corporationID' => (int) $row['corporationID'], 'scope' => "esi-killmails.read_corporation_killmails.v1"], ['maxKillID' => -1]);
    $minKillID = isset($params['max_kill_id']) ? $params['max_kill_id'] : 9999999999;

    $kills = $content == "" ? [] : json_decode($content, true);
    foreach ($kills as $kill) {
        $killID = $kill['killmail_id'];
        $hash = $kill['killmail_hash'];

        $minKillID = min($killID, $minKillID);
        $maxKillID = max($killID, $maxKillID);

        $newKills += addMail($killID, $hash);
    }

    if (sizeof($kills)) {
        $params['newKills'] = $newKills;
        $params['max_kill_id'] = $minKillID;
        $params['maxKillID'] = $maxKillID;
        $params['page'] = $params['page'] + 1;

        if ((time() - $params['tokenTime']) > 600) {
            $params['tokenTime'] = time();
            CrestSSO::getAccessTokenCallback($guzzler, $row['refreshToken'], "accessTokenDone", "accessTokenFail", $params);
        } else {
            accessTokenDone($guzzler, $params, $params['content']);
        }
    } else {
        $charID = $row['characterID'];

        $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');

        $mdb->set("scopes", ['scope' => "esi-killmails.read_corporation_killmails.v1", 'corporationID' => $corpID], ['iterated' => true], true);

        $name = Info::getInfoField('characterID', $charID, 'name');
        $corpName = Info::getInfoField('corporationID', $corpID, 'name');

        if ($newKills > 0) {
            if ($name === null) $name = $charID;
            while (strlen("$newKills") < 3) $newKills = " " . $newKills;
            ZLog::add("Iterated: $newKills kills added by corp $corpName", $charID);
            if ($newKills >= 10) User::sendMessage("$newKills kills added for corp $corpName", $charID);
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

    $mdb->removeField("scopes", $params['row'], "iterated");
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;

    if (@$json['error'] == 'invalid_grant' || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", $row);
        return;
    }

    switch ($code) {
        case 403: // A 403 without an invalid_grant is invalid
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 504:
        case "": // typically a curl timeout error
            break;
        default:
            Util::out("corp token: $charID " . $ex->getMessage() . "\n" . $params['content'] . "\n" . "code $code");
    }
    sleep(1);
}
