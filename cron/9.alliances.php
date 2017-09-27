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

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("tqStatus") != "ONLINE") break;
    sleep(1);
    $id = (int) $queueAllis->next(false);
    if ($id == null) {
        exit();
    }
    $alliance = $mdb->findDoc('information', ['type' => 'allianceID', 'id' => $id]);
    $name = (string) @$alliance['name'];

    $currentInfo = $mdb->findDoc('information', ['type' => 'alliance', 'id' => $id]);

    $alliCrest = CrestTools::getJSON("$crestServer/alliances/$id/");
    if ($alliCrest == null || !isset($alliCrest['name'])) {
        $mdb->set('information', ['type' => 'alliance', 'id' => $id], ['lastApiUpdate' => $mdb->now()]);
        continue;
    }

    $update = [];
    $update['lastApiUpdate'] = $mdb->now();
    $update['corpCount'] = (int) $alliCrest['corporationsCount'];
    $update['executorCorpID'] = (int) @$alliCrest['executorCorporation']['id'];
    addCorp($update['executorCorpID']);
    $memberCount = 0;
    $update['deleted'] = $alliCrest['deleted'];

    $mdb->set('information', ['type' => 'corporationID', 'allianceID' => $id], ['allianceID' => 0]);
    if ($alliCrest['corporations']) {
        foreach ($alliCrest['corporations'] as $corp) {
            $corpID = (int) $corp['id'];
            addCorp($corpID);
            $infoCorp = $mdb->findDoc('information', ['type' => 'corporationID', 'id' => $corpID]);
            $memberCount += ((int) @$infoCorp['memberCount']);
            $mdb->set('information', ['type' => 'corporationID', 'id' => $corpID], ['allianceID' => $id]);
        }
    }
    $update['memberCount'] = $memberCount;
    $update['ticker'] = $alliCrest['shortName'];
    $update['name'] = $alliCrest['name'];

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
