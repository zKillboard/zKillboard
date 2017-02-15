<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$waitTime = 3600;
$ssoCorps = new RedisTimeQueue("zkb:ssoCorps", $waitTime);

$count = $mdb->count("scopes", ['scope' => 'corporationKillsRead']);
if (date('i') == 5 || ($ssoCorps->size() < ($count * 0.9))) {
    populate($mdb, $ssoCorps, $waitTime, "scopes", ['scope' => 'corporationKillsRead']);
}

$guzzler = new Guzzler(10, 25000);
$minute = date('Hi');
$count = 0;
$maxConcurrent = 10;
while ($minute == date('Hi')) {
    $row = findNext($mdb, $ssoCorps);
    if ($row != null) {
        $charID = (int) $row['characterID'];
        $refreshToken = $row['refreshToken'];
        $accessToken = CrestSSO::getAccessToken($charID, null, $row['refreshToken']);
        if (checkToken($mdb, $row, $accessToken, $charID, $refreshToken) == false) continue;

        $url = "$apiServer/corp/KillMails.xml.aspx?characterID=$charID&accessToken=$accessToken&accessType=corporation";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row, 'ssoCorps' => $ssoCorps];
        $guzzler->call($url, "handleKillFulfilled", "handleKillRejected", $params);
    }
    $guzzler->tick();
}
$guzzler->finish();

function checkToken($mdb, $row, $accessToken, $charID, $refreshToken)
{
    if (@$accessToken['error'] == 'invalid_grant') {
        $mdb->remove("scopes", $row);
        Util::out("$charID corporationKillsRead removed. No longer valid");
        return false;
    }
    return true;
}

function findNext($mdb, $ttlc)
{
    $row = $mdb->findDoc("scopes", ['scope' => 'corporationKillsRead', 'corporationID' => ['$exists' => false]]);
    if ($row != null) {
        $mdb->set("scopes", $row, ['corporationID' => 0]);
    } else { 
        $corpID = (int) $ttlc->next();
        $row = $mdb->findDoc("scopes", ['scope' => 'corporationKillsRead', 'corporationID' => $corpID], ['lastApiUpdate' => 1]);
        if ($row === null) $ttlc->remove($corpID);
    }
    return $row;
}

function handleKillFulfilled(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $row = $params['row'];
    $charID = $row['characterID'];
    $charName = Info::getInfoField('characterID', $charID, 'name');
    $corpID = (int) Info::getInfoField('characterID', $charID, 'corporationID');
    $corpName = Info::getInfoField('corporationID', $corpID, 'name');
    $ssoCorps = $params['ssoCorps'];

    $xml = @simplexml_load_string($content);

    $rows = isset($xml->result->rowset->row) ? $xml->result->rowset->row : [];
    $added = 0;
    foreach ($rows as $c => $killmail) {
        $killID = (int) $killmail['killID'];
        $hash = getHash($killmail);
        $added += addKillmail($mdb, $killID, $hash);
    }

    if ($redis->get("apiVerified:$corpID") == null) {
        ZLog::add("$corpName ($charName) is now SSO/API Verified", $charID);
    }
    if ($added) {
        while (strlen("$added") < 3) $added = " $added";
        ZLog::add("$added kills added by corp $corpName (SSO)", $charID);
    }
    $redis->setex("apiVerified:$corpID", 86400, time());
    $mdb->set("scopes", $row, ['characterID' => $charID, 'corporationID' => $corpID, 'lastApiUpdate' => $mdb->now()]);
    if ($corpID != null) {
        $mdb->remove("apis", ['corporationID' => $corpID]);
        $ssoCorps->add($corpID);
    }
    xmlLog(true);
}

function handleKillRejected(&$guzzler, &$params, &$connectionException)
{
    $code = $connectionException->getCode();
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $row = $params['row'];
    $charID = $row['characterID'];
    $corpID = (int) Info::getInfoField('characterID', $charID, 'corporationID');

    switch ($code) {
        case 0: // timeout
        case 200: // timeout, server took too long to send full response
        case 503: // server error
            // Ignore for now
            break;
        case 403:
            $mdb->remove("scopes", $row);
            break;
        default:
            Util::out("/corp/KillMail fetch failed for $charID with http code $code");
    }
    xmlLog(false);
}

function xmlLog($success)
{
    $xmlSuccess = new RedisTtlCounter('ttlc:Xml' . ($success ? "Success" : "Failure"), 300);
    $xmlSuccess->add(uniqid());
}

function addKillmail($mdb, $killID, $hash)
{  
    if ($mdb->count('crestmails', ['killID' => $killID, 'hash' => $hash]) == 0) {
        $mdb->insert('crestmails', ['killID' => $killID, 'hash' => $hash, 'source' => 'api', 'processed' => false, 'added' => $mdb->now()]);

        return 1;
    }

    return 0;
}

function getHash($killmail)
{
    $victim = $killmail->victim;
    $victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

    $attackers = $killmail->rowset->row;
    $first = null;
    $attacker = null;
    foreach ($attackers as $att) {
        $first = $first == null ? $att : $first;
        if ($att['finalBlow'] != 0) {
            $attacker = $att;
        }
    }
    $attacker = $attacker == null ? $first : $attacker;
    $attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

    $shipTypeID = $victim['shipTypeID'];
    $dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

    $string = "$victimID$attackerID$shipTypeID$dttm";
    $hash = sha1($string);

    return $hash;
}

function populate($mdb, $rtq, $waitTime, $collection, $query)
{
    $rows = $mdb->find($collection, $query);
    foreach ($rows as $row) {
        $corpID = @$row['corporationID'];
        if ($corpID > 0) {
            $time = (int) @$row['lastApiUpdate']->sec;
            $rtq->add($corpID);
            $rtq->setTime($corpID, $time + $waitTime);
        }
    }
}
