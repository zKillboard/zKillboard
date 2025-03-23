<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:universeLoaded") != "true") exit();

$mdb = new Mdb();
$guzzler = new Guzzler();

$currentSecond = "";
$minute = date('Hi');
while ($minute == date('Hi')) {
    $row = $mdb->findDoc("information", ['type' => 'allianceID', 'id' => ['$gt' => 1]], ['lastApiUpdate' => 1]);
    if ($row == null) {
        sleep(1);
        continue;
    }
    $id = $row['id'];
    while ($currentSecond == date('His')) usleep(50);
    $currentSecond = date('His');

    $guzzler->call("$esiServer/v4/alliances/$id/", "success", "fail", ['id' => $id]);
    $guzzler->finish();
}
$guzzler->finish();

function success(&$guzzler, &$params, $content) 
{
    global $mdb, $esiServer;

    $id = $params['id'];
    if ($content == "") return;

    $content = str_replace('\u', '', $content);
    $alliCrest = json_decode($content, true);
    if (@$alliCrest['name'] == "") return; // Something wrong with the data, ignore for now

    $currentInfo = $mdb->findDoc('information', ['type' => 'allianceID', 'id' => $id]);

    $update = $alliCrest;
    $update['lastApiUpdate'] = $mdb->now();
    $update['executorCorpID'] = (int) @$alliCrest['executor_corporation_id'];
    addCorp($update['executorCorpID']);

    $memberCount = 0;
    $corps = $mdb->find("information", ['type' => 'corporationID', 'allianceID' => $id]);
    $update['corpCount'] = sizeof($corps);
    foreach ($corps as $corp) {
        $memberCount += @$corp['memberCount'];
    }
    $update['memberCount'] = $memberCount;
    if (@$currentInfo['obscene'] == true) {
        $update['name'] = "Alliance " . $id;
        $update['ticker'] = (string) $id;
        $update['obscene_name'] = $alliCrest['name'];
        $update['obscene_ticker'] = $alliCrest['ticker'];
    } else {
        $update['name'] = $alliCrest['name'];
        $update['ticker'] = $alliCrest['ticker'];
    }
    $update['factionID'] = (int) @$alliCrest['faction_id'];

    $eWarCount = $mdb->count("information", ['type' => 'corporationID', 'allianceID' => $id, 'war_eligible' => true]);
    $update['war_eligible'] = ($eWarCount > 0);

    $currentWar = $mdb->findDoc("information", ['type' => 'warID', 'finished' => ['$exists' => false], '$or' => [['aggressor.alliance_id'=> $id], ['defender.alliance_id'=> $id]]]);
    $update['has_wars'] = ($currentWar != null);

    $mdb->insertUpdate('information', ['type' => 'allianceID', 'id' => $id], $update);
}

function addCorp($id)
{
    global $mdb;

    $query = ['type' => 'corporationID', 'id' => (int) $id];
    $infoCorp = $mdb->findDoc('information', $query);
    if ($infoCorp == null) {
        $mdb->insertUpdate('information', $query);
    }
}

function fail($guzzler, $params, $ex)
{
    $code = $ex->getCode();
    switch ($code) {
        case 400:
        case 420:
        case 502:
        case 503:
        case 504:
            // Ignore
            break;
        default:
            Util::out($ex->getCode() . " " . $ex->getMessage());
    }
}
