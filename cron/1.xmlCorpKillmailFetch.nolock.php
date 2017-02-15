<?php

use cvweiss\redistools\RedisTimeQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$xmlCorps = new RedisTimeQueue("zkb:xmlCorps", 3600);

$size = $mdb->count("apis");
if ($size == 0) exit();

if (date('i') == 5 || $xmlCorps->size() == 0) {
    $rows = $mdb->find("apis");
    foreach ($rows as $row) {
        $xmlCorps->add((string) $row['_id']);
    }
}

$guzzler = new Guzzler(10, 25000);
$minute = date('Hi');
$count = 0;
$maxConcurrent = 10;
while ($minute == date('Hi')) {
    $id = $xmlCorps->next();
    $row = $mdb->findDoc("apis", ['_id' => new MongoId($id)]);
    if ($row != null) {
        $keyID = $row['keyID'];
        $vCode = $row['vCode'];
        $url = "$apiServer/account/APIKeyInfo.xml.aspx?keyID=$keyID&vCode=$vCode";

        $params = ['row' => $row, 'mdb' => $mdb, 'redis' => $redis];
        $guzzler->call($url, "handleInfoFulfilled", "handleInfoRejected", $params);
    } else {
        $xmlCorps->remove($id);
    }
    $guzzler->tick();
}
$guzzler->finish();

function handleInfoFulfilled(&$guzzler, &$params, &$content)
{
    global $apiServer;
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $row = $params['row'];

    $xml = @simplexml_load_string($content);

    if (!isset($xml->result->key['accessMask'])) {
        // Something went wrong
        //$redis->rpush("zkb:apis", $row['keyID']);
        xmlLog(false);
        return;
    }

    $accessMask = (int) (string) $xml->result->key['accessMask'];
    if (!($accessMask & 256)) {
        Util::out("Removing keyID " . $row['keyID'] . " - does not have a mask of 256\n");
        $mdb->remove("apis", $row);
        return;
    }

    // Get the type of API key we are working with here
    $type = $xml->result->key['type'];
    $type = $type == 'Account' ? 'Character' : $type;

    if ($type == 'Character') {
        Util::out("Removing keyID " . $row['keyID'] . " - not a corporation key");
        $mdb->remove("apis", $row);
        return;
    }

    $charID = null;
    $corpID = null;
    $rows = $xml->result->key->rowset->row;
    foreach ($rows as $c => $entity) {
        $charID = (int) $entity['characterID'];
        $corpID = (int) $entity['corporationID'];
        $corpName = (string) $entity['corporationName'];
        $keyID = $row['keyID'];
        $vCode = $row['vCode'];

        $url = "$apiServer/corp/KillMails.xml.aspx?characterID=$charID&keyID=$keyID&vCode=$vCode";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'corpID' => $corpID, 'corpName' => $corpName, 'charID' => $charID, 'keyID' => $keyID, 'row' => $row];
        $guzzler->call($url, "handleKillFulfilled", "handleKillRejected", $params);
    }
    $mdb->set("apis", $row, ['userID' => $charID, 'corporationID' => $corpID, 'lastApiUpdate' => $mdb->now()]);

    $xmlSuccess = new RedisTtlCounter('ttlc:XmlSuccess', 300);
    $xmlSuccess->add(uniqid());
}

function handleKillFulfilled(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $corpID = $params['corpID'];
    $corpName = $params['corpName'];
    $charID = $params['charID'];
    $charName = Info::getInfoField("characterID", $charID, 'name');

    $xml = @simplexml_load_string($content);

    $rows = isset($xml->result->rowset->row) ? $xml->result->rowset->row : [];
    $added = 0;
    foreach ($rows as $c => $killmail) {
        $killID = (int) $killmail['killID'];
        $hash = getHash($killmail);
        $added += addKillmail($mdb, $killID, $hash);
    }

    if ($redis->get("apiVerified:$corpID") == null) {
        ZLog::add("$corpName ($charName) is now XML/API Verified", $charID);
    }
    if ($added) {
        while (strlen("$added") < 3) $added = " $added";
        ZLog::add("$added kills added by corp $corpName (XML)", $charID);
    }
    $redis->setex("apiVerified:$corpID", 86400, time());
    xmlLog(true);
    if (date('n') > 2) {
        $row = $params['row'];
        $mdb->remove("apis", $row);
    }
}

function handleKillRejected(&$guzzler, &$params, &$connectionException)
{
    $code = (int) $connectionException->getCode();
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $keyID = $params['keyID'];
    $row = $params['row'];
    $corpID = (int) $params['corpID'];
    $corpName = Info::getInfoField("corporationID", $corpID, 'name');

    switch ($code) {
        case 0: // timeout
        case 200: // timeout, server too slow sending full response
        case 503: // server error
            //$redis->rPush("zkb:apis", $keyID);
            break;
        case 403:
            Util::out("$corpName, key $keyID, 403'ed - removing");
            $mdb->remove("apis", $row);
            break;
        default:
            Util::out("/corp/KillMail fetch failed for $keyID with http code $code");
    }
    xmlLog(false);
}

function handleInfoRejected(&$guzzler, &$params, &$connectionException)
{
    $code = (int) $connectionException->getCode();
    $mdb = $params['mdb'];
    $row = $params['row'];

    $keyID = $row['keyID'];
    switch ($code) {
        case 0: // timeout
        case 200: // timeout, server too slow sending full response
        case 503: // Server error
            break;
        case 403: // Invalid key
            $mdb->remove("apis", $row);
            break;
        default: 
            Util::out("ApiKeyInfo fetch failed for $keyID with code $code");
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
