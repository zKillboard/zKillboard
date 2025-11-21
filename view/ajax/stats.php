<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

    try {
        $params = URI::validate($uri, ['epoch' => false, 'type' => true, 'id' => true]);
    } catch (Exception $e) {
        // If validation fails, return empty JSON result
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode([]));
        return $response;
    }

    $epoch = (int) @$params['epoch'];
    $type = $params['type'];
    $id = $params['id'];

    if ($type != 'label') $id = (int) $id;

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
    if ($array == null) $array = ['epoch' => 0];

    $sEpoch = $array['epoch'];
    if (((int) $epoch) != $sEpoch) {
        // Redirect to proper epoch URL
        $redirectUrl = "/cache/24hour/stats/?epoch=$sEpoch&type=$type&id=$id";
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    //$array['activepvp'] = (object) Stats::getActivePvpStats([$type => [$id]]);
    //$array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => $id]);
    //unset($array['info']['_id']);

    $ret = [];
    $ret['s-a-sd'] = (int) @$array['shipsDestroyed'];
    $ret['s-a-sd-r'] = Util::rankCheck(@$array['ranks']['alltime']['shipsDestroyed'] ?? 0);
    $ret['s-a-sl'] = (int) @$array['shipsLost'];
    $ret['s-a-sl-r'] = Util::rankCheck(@$array['ranks']['alltime']['shipsLost'] ?? 0);
    $ret['s-a-id'] = (int) @$array['iskDestroyed'];
    $ret['s-a-id-r'] = Util::rankCheck(@$array['ranks']['alltime']['iskDestroyed'] ?? 0);
    $ret['s-a-il'] = (int) @$array['iskLost'];
    $ret['s-a-il-r'] = Util::rankCheck(@$array['ranks']['alltime']['iskLost'] ?? 0);
    $ret['s-a-pd'] = (int) @$array['pointsDestroyed'];
    $ret['s-a-pd-r'] = Util::rankCheck(@$array['ranks']['alltime']['pointsDestroyed'] ?? 0);
    $ret['s-a-pl'] = (int) @$array['pointsLost'];
    $ret['s-a-pl-r'] = Util::rankCheck(@$array['ranks']['alltime']['pointsLost'] ?? 0);

    $ret['s-a-s-e'] = eff($ret['s-a-sd'], $ret['s-a-sl']);
    $ret['s-a-i-e'] = eff($ret['s-a-id'], $ret['s-a-il']);
    $ret['s-a-p-e'] = eff($ret['s-a-pd'], $ret['s-a-pl']);

    $p = Util::convertUriToParameters("/$type/$id/");
    $q = MongoFilter::buildQuery($p);
    $ret['ksa'] = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => 1]);
    $ret['kea'] = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => -1]);
    $ret['epoch'] = $sEpoch;
    $ret['sequence'] = @$array['sequence'];

    // Return JSON response
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    $response->getBody()->write(json_encode($ret));
    return $response;
}

function eff($a, $b) {
    $t = $a + $b;
    if ($t == 0) return "-";
    return ($a / $t) * 100;
}
