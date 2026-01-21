<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

    $bypass = strpos($uri, "/bypass/") !== false;
    $tagged = strpos($uri, "/tagged/") !== false;

    try {
        if ($tagged || $bypass) $params = URI::validate($uri, ['u' => true]);
        else $params = URI::validate($uri, ['u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);
    } catch (Exception $e) {
        // If validation fails, return empty result
        return $container->get('view')->render($response, 'components/top_killer_list.html', []);
    }

    $uri = $params['u'];
    $ks = @$params['ks'];
    $ke = @$params['ke'];
    $c = @$params['c'];

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
        return $response->withStatus(302)->withHeader('Location', "/cache/tagged/statstopisk/?u=$uri");
    }

    $disqualified = 0;
    foreach ($p as $type => $val) {
        if ($type == 'characterID' || $type == 'corporationID' || $type == 'allianceID') {
            foreach ($val as $id) {
                $information = $mdb->findDoc('information', ['type' => $type, 'id' => (int) $id, 'cacheTime' => 3600]);
                $disqualified += ((int) @$information['disqualified']);
            }        
        }
    }

    $ret = [];
    if ($ksa > 0 && strpos($uri, "/page/") === false && $disqualified == 0) {
        $p['limit'] = 10;
        $p['pastSeconds'] = 604800;
        $p['kills'] = (strpos($uri, "/losses/") === false);
        if (strpos($uri, "/label/") === false) $p['label'] = 'pvp';

        $p['limit'] = 6;
        $ret['topSet'] = Stats::getTopIsk($p);
    }

    return $container->get('view')->render($response->withHeader('Cache-Tag', "statstop,statstopisk,statstop:$cacheTagKey,$cacheTagKey"), 'components/big_top_list.html', $ret);
}
