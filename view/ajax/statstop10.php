<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

    $validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

    $bypass = strpos($uri, "/bypass/") !== false;
    $tagged = strpos($uri, "/tagged/") !== false;

    try {
        if ($tagged || $bypass) $params = URI::validate($uri, ['u' => true, 't' => true, 's' => false]);
        else $params = URI::validate($uri, ['u' => true, 't' => true, 'ks' => !$bypass, 'ke' => !$bypass, 's' => false]);
    } catch (Exception $e) {
        // If validation fails, return empty template
        return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,statstop,statstop10'), 'components/top_killer_list.pug', []);
    }

    $uri = $params['u'];
    $topType = $params['t'];
    $sortBy = @$params['s'] == 'isk' ? 'isk' : 'kills';
    $ks = @$params['ks'];
    $ke = @$params['ke'];

    $split = explode("/", $uri);
    $cacheTagKey = $split[1] . ":" . $split[2];

    $epoch = time();
    $epoch = $epoch - ($epoch % 900);

    $p = Util::convertUriToParameters($uri);
    $q = MongoFilter::buildQuery($p);
    $q['cacheTime'] = 60;
    $ksa = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => 1]);
    $kea = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => -1]);

    if ($tagged === false) 
    if ($bypass || "$ks" != "$ksa" || "$ke" != "$kea") {
        // Redirect to proper sequence URL
        $redirectParams = ['u' => $uri, 't' => $topType];
        if ($sortBy == 'isk') $redirectParams['s'] = $sortBy;
        $redirectUrl = "/cache/tagged/statstop10/?".http_build_query($redirectParams);
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $disqualified = 0;
    if ($topType == 'characterID' || $topType == 'corporationID' || $topType == 'allianceID') {
        foreach ($p as $type => $val) {
            if ($type == 'characterID' || $type == 'corporationID' || $type == 'allianceID') {
                foreach ($val as $id) {
                    $information = $mdb->findDoc('information', ['type' => $type, 'id' => (int) $id, 'cacheTime' => 3600]);
                    $disqualified += ((int) @$information['disqualified']);
                }
            }
        }
    }

    $ret = [];
    if (strpos($uri, "/page/") === false && in_array($topType, $validTopTypes) && $disqualified == 0) {
        $p['limit'] = 10;
        $p['pastSeconds'] = 604800;
        $p['kills'] = (strpos($uri, "/losses/") === false);
        if (strpos($uri, "/label/") === false) $p['label'] = 'pvp';

        $name = ucfirst(str_replace("solar", "", str_replace("Type", "", str_replace("ID", "", $topType))));
        $topSet = Info::doMakeCommon("Top ${name}s", $topType, Stats::getTop($topType, $p, false, true, $sortBy));
        $topSet['sort'] = $sortBy;
        $topSet['sortUri'] = $uri;
        $topSet['sortType'] = $topType;
        $ret['topSet'] = $topSet;
    }

    // Return rendered template
    return $container->get('view')->render($response->withHeader('Cache-Tag', "www,statstop,statstop10,statstop:$cacheTagKey,$cacheTagKey"), 'components/top_killer_list.pug', $ret);
}
