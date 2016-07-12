<?php

require_once '../init.php';

$key = 'zkb:supersCalced:'.date('Ymd');
if ($redis->get($key) == true) {
    exit();
}

doSuperResult($mdb->find('information', ['type' => 'factionID']));
doSuperResult($mdb->find('information', ['type' => 'allianceID']));
doSuperResult($mdb->find('information', ['type' => 'corporationID']));

$redis->setex($key, 86400, true);

function doSuperResult($result)
{
    foreach ($result as $row) {
        doSupers($row);
    }
}

function doSupers($row)
{
    global $mdb;

    $type = $row['type'];
    $id = (int) $row['id'];

    $query = [$type => (int) $id, 'isVictim' => false, 'groupID' => [659, 30], 'pastSeconds' => (90 * 86400)];
    $query = MongoFilter::buildQuery($query);
    $hasSupers = $mdb->exists('killmails', $query);

    if ($hasSupers == false) {
        $mdb->set('statistics', ['type' => $type, 'id' => $id], ['hasSupers' => false, 'supers' => []]);
    } else {
        $supers = Stats::getSupers($type, $id);
        $mdb->set('statistics', ['type' => $type, 'id' => $id], ['hasSupers' => true, 'supers' => $supers]);
    }
}
