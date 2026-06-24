<?php

function handler($request, $response, $args, $container) {
    global $mdb, $uri;

    try {
        $params = URI::validate($uri, ['type' => true, 'id' => true]);
    } catch (Exception $e) {
        // If validation fails, return empty JSON result
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', 'www,stats');
        $response->getBody()->write(json_encode([]));
        return $response;
    }

    $epoch = (int) @$params['epoch'];
    $type = $params['type'];
    $id = $params['id'];
    $cacheKey = "$type:$id";
    $cacheKey = str_replace("shipType", "ship", str_replace("solarS", "s", str_replace("ID", "", "$type:$id")));

    if ($type != 'label') $id = (int) $id;

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
    if ($array == null) $array = ['epoch' => 0];

    $sEpoch = $array['epoch'];
    if (false && ((int) $epoch) != $sEpoch) {
        // Redirect to proper epoch URL
        //$redirectUrl = "/cache/24hour/stats/?epoch=$sEpoch&type=$type&id=$id";
        $redirectUrl = "/cache/tagged/stats/?type=$type&id=$id";
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    //$array['activepvp'] = (object) Stats::getActivePvpStats([$type => [$id]]);
    //$array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => $id]);
    //unset($array['info']['_id']);

    $ret = [];
    $rankRow = Ranks::getRow('alltime', 'all', $type, $id);
    $ret['s-a-sd'] = (int) @$array['shipsDestroyed'];
    $ret['s-a-sd-r'] = rankRowRank($rankRow, 'shipsDestroyed');
    $ret['s-a-sl'] = (int) @$array['shipsLost'];
    $ret['s-a-sl-r'] = rankRowRank($rankRow, 'shipsLost');
    $ret['s-a-id'] = (int) @$array['iskDestroyed'];
    $ret['s-a-id-r'] = rankRowRank($rankRow, 'iskDestroyed');
    $ret['s-a-il'] = (int) @$array['iskLost'];
    $ret['s-a-il-r'] = rankRowRank($rankRow, 'iskLost');
    $ret['s-a-pd'] = (int) @$array['pointsDestroyed'];
    $ret['s-a-pd-r'] = rankRowRank($rankRow, 'pointsDestroyed');
    $ret['s-a-pl'] = (int) @$array['pointsLost'];
    $ret['s-a-pl-r'] = rankRowRank($rankRow, 'pointsLost');

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
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', "www,stats,stats:$cacheKey");
    $response->getBody()->write(json_encode($ret));
    return $response;
}

function eff($a, $b) {
    $t = $a + $b;
    if ($t == 0) return "-";
    return ($a / $t) * 100;
}

function rankRowRank($row, $metric)
{
    return $row['ranks'][$metric] ?? null;
}
