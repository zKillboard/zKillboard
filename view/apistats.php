<?php

global $mdb, $redis;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $bid = $id;
    $oID = $id;
    $id = (int) $id;
    if ("$id" != "$oID") throw new Exception("$oID is not a valid parameter");
    if ("$bid" != "$id") throw new Exception("$bid is not a valid parameter");

    if ($type == 'shipTypeID') $type = 'typeID';
    $information = $mdb->findDoc('information', ['type' => $type, 'id' => (int) $id]);
    if ($information === null) throw new Exception("Invalid type or id");
    $disqualified = ((int) @$information['disqualified']);
    if ($disqualified != 0) {
        $app->contentType('application/json; charset=utf-8');
        echo json_encode(['error' => 'entity is disqualified']);
        return;
    }

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
    unset($array['_id']);
    unset($array['trophies']);

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
    $array['topLists'] = $topLists;
    $array['topIskKillIDs'] = array_keys(Stats::getTopIsk($p));

    $activity = ['max' => 0];
    $raw = $redis->hget("zkb:activity", $id);
    if ($raw != null) $activity = unserialize($raw);
    else for ($day = 0; $day <= 6; $day++ ) {
        for ($hour = 0; $hour <= 23; $hour++) {
            $count = $mdb->count("activity", ['id' => (int) $id, 'day' => $day, 'hour' => $hour]);
            if ($count > 0) $activity[$day][$hour] = $count;
            $activity['max'] = max($activity['max'], $count);
        }
    }
    $activity['days'] = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    if ($activity['max'] > 0) $array['activity'] = $activity;

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
}
