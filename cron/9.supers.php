<?php

require_once '../init.php';

$key = 'zkb:supersCalced:'.date('Ymd');
if ($redis->get($key) == true) {
    exit();
}

MongoCursor::$timeout = -1;

$mdb->getCollection("statistics")->update(['hasSupers' => ['$exists' => true]], ['$set' => ['updatingSupers' => true]], ['multi' => true]);

doSuperResult('allianceID');
doSuperResult('corporationID');
$mdb->getCollection("statistics")->update(['updatingSupers' => true], ['$unset' => ['updatingSupers' => 1, 'hasSupers' => 1, 'supers' => 1]], ['multi' => true]);

$redis->setex($key, 86400, true);

function doSuperResult($type)
{
    global $mdb;

    echo "Finding $type\n";
    doGroup($type, 30);
    doGroup($type, 659);
}

function doGroup($type, $groupID)
{
    global $mdb;

    $params = ['groupID' => $groupID, 'kills' => true];
    $query = MongoFilter::buildQuery($params);
    $result = $mdb->getCollection('ninetyDays')->distinct('involved.' . $type, $query);
    foreach ($result as $id) {
        doSupers($type, $id, $groupID);
    }

}

function doSupers($type, $id, $groupID)
{
    global $mdb;

    $name = Info::getInfoField('groupID', $groupID, 'name') . 's';

    $data = [];
    $data['title'] = $name;
    $parameters = ['allianceID' => 173714703, 'pastSeconds' => (86400 * 7)];

    $agg = [];
    $parameters = [$type => (int) $id, 'groupID' => $groupID, 'kills' => true];
    $result = $mdb->find("ninetyDays", MongoFilter::buildQuery($parameters));
    if (sizeof($result) == 0) return;
    foreach ($result as $killmail) {
        $involved = $killmail['involved'];
        foreach ($involved as $inv)
        {
            if (@$inv['isVictim'] == true) continue;
            if (@$inv[$type] != $id) continue;
            if (((int) @$inv['characterID']) == 0) continue;
            $charID = $inv['characterID'];
            if (@$agg[$charID] == null) $agg[$charID] = ['characterID' => $charID, 'kills' => 0];
            $agg[$charID]['kills']++;
        }
    }
    $data['data'] = $agg;

    if (sizeof($result) > 0) {
        $mdb->set('statistics', ['type' => $type, 'id' => $id], ['hasSupers' => true, 'supers.' . $name => $data, 'updatingSupers' => false]);
    }
}
