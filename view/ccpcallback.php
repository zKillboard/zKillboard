<?php

use cvweiss\redistools\RedisTimeQueue;

global $mdb, $redis, $adminCharacter;

$sso = EveOnlineSSO::getSSO();
$code = filter_input(INPUT_GET, 'code');
$state = filter_input(INPUT_GET, 'state');
$userInfo = $sso->handleCallback($code, $state, $_SESSION);

$charID = (int) $userInfo['characterID'];
$charName = $userInfo['characterName'];
$scopes = explode(' ', $userInfo['scopes']);
$refresh_token = $userInfo['refreshToken'];
$access_token = $userInfo['accessToken'];

$rtq = new RedisTimeQueue("zkb:characterID", 86400);
$rtq->add($charID, -1);

sleep(2);
$corpID = Info::getInfoField("characterID", $charID, "corporationID");

// Clear out existing scopes
if ($charID != $adminCharacter) $mdb->remove("scopes", ['characterID' => $charID]);

foreach ($scopes as $scope) {
    if ($scope == "publicData") continue;
    $row = ['characterID' => $charID, 'scope' => $scope, 'refreshToken' => $refresh_token, 'oauth2' => true];
    if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $scope]) == 0) {
        try {
            $mdb->save("scopes", $row);
        } catch (Exception $ex) {}
    }
    switch ($scope) {
        case 'esi-killmails.read_killmails.v1':
            $esi = new RedisTimeQueue('tqApiESI', 3600);
            // // Do this first, prevents race condition if charID already exists
            // If a user logs in, check their api for killmails right away
            $esi->setTime($charID, 0);

            // If we didn't already have their api, this will add it and it will be
            // checked right away as well
            $esi->add($charID);
            break;
        case 'esi-killmails.read_corporation_killmails.v1':
            $esi = new RedisTimeQueue('tqCorpApiESI', 3600);
            if ($corpID > 1999999) $esi->add($corpID);
            break;
    }
}

ZLog::add("Logged in: $charName", $charID, true);
unset($_SESSION['oauth2State']);

$key = "login:$charID:" . session_id();
$redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
$redis->setex("$key:accessToken", 1000, $access_token);
$redis->setex("$key:scopes", (86400 * 14), @$userInfo['scopes']);

$_SESSION['characterID'] = $charID;
$_SESSION['characterName'] = $charName;
session_write_close();
header('Location: /', 302);
