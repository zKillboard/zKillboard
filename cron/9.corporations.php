<?php

require_once '../init.php';

use cvweiss\redistools\RedisTimeQueue;

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:universeLoaded") != "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

$guzzler = new Guzzler();


$currentSecond = "";
$minute = date('Hi');
while ($minute == date('Hi')) {
    $t = new Timer();
    $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => ['$gt' => 1]], ['lastApiUpdate' => 1]);
    if ($row == null) {
        sleep(1);
        continue;
    }
    $id = $row['id'];

    $hasRecent = $mdb->exists("ninetyDays", ['involved.corporationID' => $id]);
    if (isset($row['lastApiUpdate']) && !$hasRecent)  {

    }
    while ($currentSecond == date('His')) usleep(50);
    $currentSecond = date('His');

    $url = "$esiServer/v5/corporations/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $a = (isset($row['lastApiUpdate']) && $row['name'] != '') ? [] : [];
    $guzzler->call($url, "updateCorp", "failCorp", $params, $a);

    $guzzler->finish();
    $time = $t->stop();
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
        case 504: // gateway timeout
        case 200: // timeout...
            //$mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        case 420:
            $guzzler->finish();
            exit();
        default:
            Util::out("/v4/corporations/ failed for $id with code $code");
    }
}

function updateCorp(&$guzzler, &$params, &$content)
{
    if ($content == "") return;

    $redis = $params['redis'];
    $mdb = $params['mdb'];
    $row = $params['row'];
    $id = $row['id'];

    $content = Util::eliminateBetween($content, '"description"', '"faction_id"');
    $content = Util::eliminateBetween($content, '"description"', '"home_station_id"');
    $content = Util::eliminateBetween($content, '"description"', '"member_count"');
    $content = Util::eliminateBetween($content, '"description"', '"name"');

    $json = json_decode($content, true);
    if ($json['name'] == "") return; // bad data, ignore it
    $ceoID = (int) $json['ceo_id'];

    $updates = $json;
    $updates['lastApiUpdate'] =  $mdb->now();
    if (isset($json['name']) && $json['name'] != "") $updates['name'] = (string) @$json['name'];
    if (isset($json['ticker']) && $json['ticker'] != "") $updates['ticker'] = (string) @$json['ticker'];
    $updates['ceoID'] = (int) @$json['ceo_id'];
    $updates['memberCount'] = (int) @$json['member_count'];
    $updates['allianceID'] = (int) @$json['alliance_id'];
    $updates['factionID'] = (int) @$json['faction_id'];
    $updates['war_eligible'] = (isset($json['war_eligible']) ? $json['war_eligible'] : false);

    $currentWar = $mdb->findDoc("information", ['type' => 'warID', 'finished' => ['$exists' => false], '$or' => [['aggressor.corporation_id'=> $id], ['defender.corporation_id'=> $id]]]);
    $updates['has_wars'] = ($currentWar != null);

    // Does the CEO exist in our info table?
    $ceoExists = $mdb->count('information', ['type' => 'characterID', 'id' => $ceoID]);
    if ($ceoExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'characterID', 'id' => $ceoID], []);
    }

    if (sizeof($updates)) {
        $mdb->set("information", $row, $updates);
        $redis->del(Info::getRedisKey('corporationID', $row['id']));
    }

    if (isset($json['alliance_id'])) {
        $exists = $mdb->exists("information", ['type' => 'allianceID', 'id' => (int) $json['alliance_id']]);
        if ($exists == false) $mdb->insert("information", ['type' => 'allianceID', 'id' => (int) $json['alliance_id'], 'name' => 'allianceID ' . (int) $json['alliance_id']]);
    }
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
