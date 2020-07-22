<?php
exit();

require_once '../init.php';

$key = 'zkb:supersCalced:'.date('Ymd');
if ($redis->get($key) == true) {
    exit();
}

MongoCursor::$timeout = -1;

$query = ['isVicitm' => false, 'groupID' => [659, 30]];
$query = MongoFilter::buildQuery($query);
$result = $mdb->getCollection("ninetyDays")->distinct('involved.corporationID', $query);
print_r($result);

$result = $mdb->getCollection("ninetyDays")->distinct('involved.allianceID', $query);
print_r($result);
die();

//doSuperResult($mdb->find('information', ['type' => 'factionID']));
echo "alliance search\n";
doSuperResult($mdb->find('information', ['type' => 'allianceID']));
echo "corp search\n";
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

    $info = $mdb->findDoc("information", ['type' => $type, 'id' => $id]);
    if (@$info['memberCount'] == 0) return;
    echo "$type $id\n";

    $query = ['isVicitm' => false, 'groupID' => [659, 30]];
    $query = MongoFilter::buildQuery($query);
    $result = $mdb->getCollection("ninetyDays")->distinct('involved.' . $type, $query);
    print_r($result); 
    die();
    //$hasSupers = $mdb->exists('ninetyDays', $query);
    $hasSupers = false;

    if ($hasSupers == false) {
        $mdb->set('statistics', ['type' => $type, 'id' => $id], ['hasSupers' => false, 'supers' => []]);
    } else {
        $supers = Stats::getSupers($type, $id);
        $mdb->set('statistics', ['type' => $type, 'id' => $id], ['hasSupers' => true, 'supers' => $supers]);
    }
}
