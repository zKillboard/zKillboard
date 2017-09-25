<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

$failure = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
$guzzler = new Guzzler();
$corps = new RedisTimeQueue("zkb:corporationID", 86400);

$minute = date('Hi');
while ($minute == date('Hi') && $failure->count() < 300) {
    if ($redis->get("tqStatus") == "OFFLINE") break;
    $id = (int) $corps->next();
    if ($id <= 0) break;
    if ($id > 0) {
        $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => $id]);

        $url = "https://esi.tech.ccp.is/v3/corporations/$id/";
        $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
        $guzzler->call($url, "updateCorp", "failCorp", $params);
        if ($failure->count() > 200) sleep(1);
    }
}
$guzzler->finish();

function failCorp(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 500:
        case 502: // ccp broke something
        case 503: // server error
        case 200: // timeout...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        default:
            Util::out("/v3/corporation/ failed for $id with code $code");
    }
    $failure = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
    $failure->add(uniqid());
}

function updateCorp(&$guzzler, &$params, &$content)
{
    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];

    $json = json_decode($content, true);

    $ceoID = (int) $json['ceo_id'];

    $updates = [];
    compareAttributes($updates, "name", @$row['name'], (string) $json['corporation_name']);
    compareAttributes($updates, "ticker", @$row['ticker'], (string) $json['ticker']);
    compareAttributes($updates, "ceoID", @$row['ceoID'], $ceoID);
    compareAttributes($updates, "memberCount", @$row['memberCount'], (int) $json['member_count']);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) @$json['alliance_id']); 
    compareAttributes($updates, "factionID", @$row['factionID'], (int) @$json['faction_id']);

    // Does the CEO exist in our info table?
    $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
    if ($ceoExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], []);
    }

    if (sizeof($updates)) {
        $mdb->set("information", $row, $updates);
        $redis->del(Info::getRedisKey('corporationID', $row['id']));
    }
    $success = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiSuccess', 300);
    $success->add(uniqid());
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
