<?php


use cvweiss\redistools\RedisTimeQueue;

require_once "../init.php";

$sso = EveOnlineSSO::getSSO();

if ($redis->get("zkb:reinforced") == true) exit();
if ($redis->get("zkb:noapi") == "true") exit();

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
        $row = $mdb->findDoc("scopes", ['corporationID' => $corpID, 'scope' => "esi-killmails.read_corporation_killmails.v1", 'oauth2' => true], ['lastFetch' => 1]);
        if ($row != null) {
            $charID = $row['characterID'];
            $refreshToken = $row['refreshToken'];
            $params = ['row' => $row, 'esi' => $esi, 'tokenTime' => time(), 'refreshToken' => $refreshToken, 'corpID' => $corpID];

            $refreshToken = $row['refreshToken'];
            $accessToken = $redis->get("oauth2:$charID:$refreshToken");
            if ($accessToken == null) {
                $accessToken = $sso->getAccessToken($refreshToken);
                if (isset($accessToken['error'])) {
                    $mdb->remove("scopes", $row);
                    sleep(1);
                    continue;
                }
                $redis->setex("oauth2:$charID:$refreshToken", 900, $accessToken);
            }
            $killmails = $sso->doCall("$esiServer/v1/corporations/$corpID/killmails/recent/", [], $accessToken);
            success(['corpID' => $corpID, 'row' => $row, 'esi' => $esi], $killmails);
        } else {
            $esi->remove($corpID);
        }
    } else {
        sleep(1);
    }
}

function success($params, $content) 
{
    global $mdb, $redis;

    $row = $params['row'];
    $esi = $params['esi'];

    $newKills = 0;
    $kills = $content == "" ? [] : json_decode($content, true);
    if (!is_array($kills)) {
        print_r($kills);
        return;
    }
    if (isset($kills['error'])) {
        // Something went wrong, reset it and try again later
        $esi->add($row['characterID'], 0);
        return;
    }
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

    /*$headers = $guzzler->getLastHeaders();
    if ($redis->get("recentKillmailActivity:$corpID") == "true") {
        $headers = $guzzler->getLastHeaders();
        $expires = $headers['expires'][0];
        $time = strtotime($expires);
        $esi->setTime($charID, $time + 2);
    }*/
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
