<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

    $validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

    $bypass = strpos($uri, "/bypass/") !== false;

    try {
        $params = URI::validate($uri, ['u' => true, 't' => true, 'ks' => !$bypass, 'ke' => !$bypass]);
    } catch (Exception $e) {
        // If validation fails, return empty template
        return $container->get('view')->render($response, 'components/top_killer_list.html', []);
    }

    $uri = $params['u'];
    $topType = $params['t'];
    $ks = @$params['ks'];
    $ke = @$params['ke'];

    $epoch = time();
    $epoch = $epoch - ($epoch % 900);

    $p = Util::convertUriToParameters($uri);
    $q = MongoFilter::buildQuery($p);
    $q['cacheTime'] = 60;
    $ksa = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => 1]);
    $kea = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => -1]);

    if ($bypass || "$ks" != "$ksa" || "$ke" != "$kea") {
        // Redirect to proper sequence URL
        $redirectUrl = "/cache/24hour/statstop10/?u=$uri&t=$topType&ks=$ksa&ke=$kea";
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $disqualified = 0;
    if ($topType == 'characterID' || $topType == 'corportionID' || $topType == 'allianceID') {
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
        $ret['topSet'] = Info::doMakeCommon("Top ${name}s", $topType, Stats::getTop($topType, $p));
    }

    // Return rendered template
    return $container->get('view')->render($response, 'components/top_killer_list.html', $ret);
}
