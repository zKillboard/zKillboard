<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$guzzler = new Guzzler();
$minute = date('Hi');
$count = 0;
$maxConcurrent = 10;
while ($minute == date('Hi')) {
    $row = $mdb->findDoc("scopes", ['scope' => 'corporationKillsRead'], ['lastApiUpdate' => 1]);
    if ($row != null && @$row['lastApiUpdate']->sec < (time() - 1800)) {
        $mdb->set("scopes", $row, ['lastApiUpdate' => $mdb->now()]);
        $charID = $row['characterID'];
        $accessToken = CrestSSO::getAccessToken($charID, null, $row['refreshToken']);

        $url = "$apiServer/corp/KillMails.xml.aspx?characterID=$charID&accessToken=$accessToken&accessType=corporation";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
        $guzzler->call($url, "handleKillFulfilled", "handleKillRejected", $params);
    }
    $guzzler->tick();
    usleep(250000);
}
$guzzler->finish();

function handleKillFulfilled(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $row = $params['row'];
    $charID = $row['characterID'];
    $charName = Info::getInfoField('characterID', $charID, 'name');
    $corpID = Info::getInfoField('characterID', $charID, 'corporationID');
    $corpName = Info::getInfoField('corporationID', $corpID, 'name');

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
    $mdb->set("scopes", $row, ['charcterID' => $charID, 'corporationID' => $corpID]);
    $mdb->remove("apis", ['corporationID' => $corpID]);
    xmlLog(true);
}

function handleKillRejected(&$guzzler, &$params, &$connectionException)
{
    $code = $connectionException->getCode();
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $row = $params['row'];
    $charID = $row['characterID'];
    $corpID = Info::getInfoField('characterID', $charID, 'corporationID');

    switch ($code) {
        case 0: // timeout
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
