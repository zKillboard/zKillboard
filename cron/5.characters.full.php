<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

if ($redis->get("zkb:reinforced") == true) exit();

$guzzler = new Guzzler(1);

$mdb->removeField("scopes", ['iterated' => 'in progress'], "iterated");

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::checkStatus($guzzler, 'esi');
    Status::checkStatus($guzzler, 'sso');
    Status::throttle('sso', 20);
    $row = $mdb->findDoc("scopes", ['scope' => "esi-killmails.read_killmails.v1", 'iterated' => ['$exists' => false]], ['_id' => -1]);
    if ($row == null) break;

    $params = ['row' => $row, 'page' => 1];
    $mdb->set("scopes", $row, ['iterated' => 'in progress']);
    $refreshToken = $row['refreshToken'];
    CrestSSO::getAccessTokenCallback($guzzler, $refreshToken, "accessTokenDone", "accessTokenFail", $params);
    $guzzler->tick();
}
$guzzler->finish();


function accessTokenDone(&$guzzler, &$params, $content, $cacheIt = true)
{
    global $ccpClientID, $ccpSecret, $redis, $esiServer;

    $row = $params['row'];
    $charID = $row['characterID'];
    $refreshToken = $row['refreshToken'];
    if ($cacheIt == true) {
        $redis->setex("auth:$charID:$refreshToken", 1100, $content);
    }

    $response = json_decode($content, true);
    $accessToken = $response['access_token'];
    $params['content'] = $content;

    $headers = [];
    $headers['Content-Type'] ='application/json';
    $headers['Authorization'] = "Bearer $accessToken";

    $fields = [];
    if (isset($params['page'])) {
        $fields['page'] = $params['page'];
    }
    $fields = ESI::buildparams($fields);
    $url = "$esiServer/v1/characters/$charID/killmails/recent/?$fields";
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

        accessTokenDone($guzzler, $params, $params['content']);
    } else {
        $charID = $row['characterID'];

        $corpID = (int) Info::getInfoField("characterID", $charID, 'corporationID');
        $successes = (int) @$row['successes'];
        $successes++;
        $mdb->set("scopes", $row, ['iterated' => true, 'maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now(), 'errorCount' => 0, 'successes' => $successes]);
        $mdb->set("scopes", ['characterID' => $charID], ['corporationID' => $corpID]);

        if ($newKills > 0) {
            $name = Info::getInfoField('characterID', $charID, 'name');
            if ($name === null) $name = $charID;
            while (strlen("$newKills") < 3) $newKills = " " . $newKills;
            ZLog::add("Iterated: $newKills kills added by char $name", $charID);
            if ($newKills >= 10) User::sendMessage("$newKills kills added for char $name", $charID);
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
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    $code = isset($json['sso_status']) ? $json['sso_status'] : $code;

    switch ($code) {
        case 400: // Server timed out during SSO authentication
        case 420:
        case 500:
        case 502: // Server error, try again in 5 minutes
        case 503:
        case 504: // gateway timeout
        case "": // typically a curl timeout error
            break;
        case 403: // Server decided to throw a 403 during SSO authentication when that throws a 502...
        default:
            echo "killmail char $charID: " . $ex->getMessage() . "\nkillmail content: " . $params['content'] . "\n";
    }
    sleep(1);
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    $json = json_decode($params['content'], true);
    if (@$json['error'] == 'invalid_grant' || @$json['error'] == 'invalid_token') {
        $mdb->remove("scopes", ['characterID' => $charID]);
        $esi->remove($charID);
        return;
    }

    switch ($code) {
        case 403: // A 403 without an invalid_grant isn't valid
        case 500:
        case 502: // Server error, try again in 5 minutes
        case "": // typically a curl timeout error
            $esi->setTime($charID, time() + 30);
            break;
        default:
            Util::out("char token: $charID " . $ex->getMessage() . "\n\n" . $params['content']);
    }
    sleep(1);
}
