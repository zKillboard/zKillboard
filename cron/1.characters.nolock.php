<?php

use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$guzzler = new Guzzler(20, 1);

$esi = new RedisTimeQueue('tqApiESI', 3600);
if (date('i') == 22 || $esi->size() == 0) {
    $esis = $mdb->find("scopes", ['scope' => 'esi-killmails.read_killmails.v1']);
    foreach ($esis as $row) {
        $charID = $row['characterID'];
        $esi->add($charID);
    }
}

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("tqStatus") != "ONLINE") break;
    $charID = (int) $esi->next();
    if ($charID > 0) {
        $row = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => "esi-killmails.read_killmails.v1"], ['lastFetch' => 1]);
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
    $fields = ['datasource' => 'tranquility', 'token' => $accessToken];
    if (isset($params['max_kill_id'])) {
        $fields['max_kill_id'] = $params['max_kill_id'];
    }
    $fields = ESI::buildparams($fields);
    $url = "https://esi.tech.ccp.is/v1/characters/$charID/killmails/recent/?$fields";

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
        $mdb->set("scopes", $row, ['maxKillID' => $maxKillID, 'corporationID' => $corpID, 'lastFetch' => $mdb->now()]);
        $redis->setex("apiVerified:$charID", 86400, time());

        // Check active chars once an hour, check inactive chars less often
        $esi = new RedisTimeQueue('tqApiESI', 3600);
        if ($maxKillID > ($redis->get('zkb:topKillID') - 1000000)) {
            $numHours = rand(24,48);
            $esi->setTime($charID, time() + (3600 * $numHours));
        }

        if ($newKills > 0) {
            $name = Info::getInfoField('characterID', $charID, 'name');
            if ($name === null) $name = $charID;
            while (strlen("$newKills") < 3) $newKills = " " . $newKills;
            ZLog::add("$newKills kills added by char $name", $charID);
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
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    switch ($code) {
        case 400:
        case 403: // No permission
            $mdb->remove("scopes", $row);
            $esi->remove($charID);
            break;
        case 500:
        case 502: // Server error, try again in 5 minutes
            $esi->setTime($charID, time() + 300);
            break;
        default:
            echo "token: " . $ex->getMessage() . "\n";
    }
}

function accessTokenFail(&$guzzler, &$params, $ex)
{
    global $mdb;

    $row = $params['row'];
    $esi = $params['esi'];
    $charID = $row['characterID'];
    $code = $ex->getCode();

    switch ($code) {
        case 400:
        case 403: // No permission
            $mdb->remove("scopes", $row);
            $esi->remove($charID);
            break;
        case 500:
        case 502: // Server error, try again in 5 minutes
            $esi->setTime($charID, time() + 300);
            break;
        default:
            echo "token: " . $ex->getMessage() . "\n";
    }
}
