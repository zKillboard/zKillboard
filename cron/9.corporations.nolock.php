<?php

require_once '../init.php';

$failure = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
$guzzler = new Guzzler();

$minute = date('Hi');
while ($minute == date('Hi') && $failure->count() < 300) {
    $row = $mdb->findDoc("information", ['type' => 'corporationID'], ['lastApiUpdate' => 1]);
    if ($row === null) break;

    $id = $row['id'];
    if ((time() - @$row['lastApiUpdate']->sec) < 86400) break;
    $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()]);

    $url = "https://esi.tech.ccp.is/v3/corporations/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateCorp", "failCorp", $params);
    if ($failure->count() > 200) sleep(1);
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
    }
    $success = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiSuccess', 300);
    $success->add(uniqid());
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
