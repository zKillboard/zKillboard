<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

$failure = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
$guzzler = new Guzzler(20, 1);
$chars = new RedisTimeQueue("zkb:characterID", 86400);

$minute = date('Hi');
while ($minute == date('Hi') && $failure->count() < 300) {
    $id = (int) $chars->next();
    if ($id == 0) {
        usleep(100000);
        continue;
    }
    $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $id]);
    if (@$row['corporationID'] == 1000001) continue;

    $url = "https://esi.tech.ccp.is/v4/characters/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateChar", "failChar", $params);
    if ($failure->count() > 200) sleep(1);
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something...
        case 503: // server error
        case 200: // timeout...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        case 404:
        case 410:
            $mdb->set("information", $row, ['allianceID' => 0, 'corporationID' => 1000001, 'factionID' => 0,  'secStatus' => 0]);
            break;
        default:
            Util::out("/v4/characters/ failed for $id with code $code");
    }
    $xmllog = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
    $xmllog->add(uniqid());
}

function updateChar(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $row = $params['row'];
    $json = json_decode($content, true);

    $id = $row['id'];
    $corpID = (int) $json['corporation_id'];

    $updates = [];
    compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "corporationID", @$row['corporationID'], $corpID);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) @$json['alliance_id']);
    compareAttributes($updates, "factionID", @$row['factionID'], 0);
    compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
    if ($corpExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
    }

    if (sizeof($updates) > 0) {
        $mdb->set("information", $row, $updates);
    }
    $success = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiSuccess', 300);
    $success->add(uniqid());
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
