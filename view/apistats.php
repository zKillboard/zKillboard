<?php

global $mdb;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $bid = $id;
    $oID = $id;
    $id = (int) $id;
    if ("$id" != "$oID") throw new Exception("$oID is not a valid parameter");
    if ("$bid" != "$id") throw new Exception("$bid is not a valid parameter");

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
    unset($array['_id']);

    $array['activepvp'] = (object) Stats::getActivePvpStats([$type => [$id]]);
    $array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => $id]);
    unset($array['info']['_id']);

    //Stats::getSupers($array, $type, $id);

    $p = [$type => [$id]];
    $numDays = 7;
    $p['limit'] = 10;
    $p['pastSeconds'] = $numDays * 86400;
    $p['kills'] = true;

    $topLists[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
    $topLists[] = Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p));
    $topLists[] = Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p));
    $topLists[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
    $topLists[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));
    $topLists[] = Info::doMakeCommon('Top Locations', 'locationID', Stats::getTop('locationID', $p));

    $p['limit'] = 6;
    //$p['categoryID'] = 6;
    $array['topLists'] = $topLists;
    $array['topIskKillIDs'] = array_keys(Stats::getTopIsk($p));

    if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
        $app->contentType('application/javascript; charset=utf-8');
        header('X-JSONP: true');
        echo $_GET['callback'].'('.json_encode($array).')';
    } else {
        $app->contentType('application/json; charset=utf-8');
        echo json_encode($array);
    }
} catch (Exception $ex) {
    //header('HTTP/1.0 503 Server error.');
    header('Content-Type: application/json');
    $error = ['error' => $ex->getMessage()];
    echo json_encode($error);
    die();
}
