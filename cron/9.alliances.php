<?php

use cvweiss\redistools\RedisTimeQueue;

require_once '../init.php';

$mdb = new Mdb();
$old = $mdb->now(3600 * 3); // 8 hours
$queueAllis = new RedisTimeQueue('tqAlliances', 9600);

$i = date('i');
if ($i == 45) {
    $allis = $mdb->find('information', ['type' => 'allianceID']);
    foreach ($allis as $alli) {
        $queueAllis->add($alli['id']);
    }
}

$guzzler = new Guzzler(2);

$minute = date('Hi');
while ($minute == date('Hi')) {
    Status::check('esi');
    $id = (int) $queueAllis->next();
    if ($id == null) break;

    $alliance = $mdb->findDoc('information', ['type' => 'allianceID', 'id' => $id]);
    $name = (string) @$alliance['name'];

    $guzzler->call("$esiServer/v3/alliances/$id/", "success", "fail", ['id' => $id]);
}
$guzzler->finish();

function success(&$guzzler, &$params, $content) 
{
    global $mdb, $esiServer;

    $alliCrest = json_decode($content, true);

    $id = $params['id'];
    $currentInfo = $mdb->findDoc('information', ['type' => 'allianceID', 'id' => $id]);

    $update = [];
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
    $update['ticker'] = $alliCrest['ticker'];
    $update['name'] = $alliCrest['name'];
    $update['factionID'] = (int) @$alliCrest['faction_id'];

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
    print_r($ex);
}
