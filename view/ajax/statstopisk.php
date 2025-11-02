<?php

global $mdb, $redis, $uri;

$bypass = strpos($uri, "/bypass/") !== false;

// Handle URI validation for compatibility
if (isset($GLOBALS['capture_render_data'])) {
    // Try to bypass URI validation for captured requests, set default params
    try {
        // Create a mock app object for URI::validate
        $mockApp = new class {
            public function notFound() {
                throw new Exception('Not Found');
            }
        };
        $params = URI::validate($mockApp, $uri, ['u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);
    } catch (Exception $e) {
        // If validation fails, return empty result
        global $twig;
        $GLOBALS['capture_render_data'] = $twig->render('components/top_killer_list.html', []);
        return;
    }
} else {
    $params = URI::validate($app, $uri, ['u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);
}

$uri = $params['u'];
$ks = @$params['ks'];
$ke = @$params['ke'];
$c = @$params['c'];

$epoch = time();
$epoch = $epoch - ($epoch % 900);

$p = Util::convertUriToParameters($uri);
$q = MongoFilter::buildQuery($p);
$q['cacheTime'] = 60;
$ksa = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => 1]);
$kea = (int) $mdb->findField('oneWeek', 'sequence', $q, ['sequence' => -1]);

if ($bypass || "$ks" != "$ksa" || "$ke" != "$kea") {
    // Handle redirect for compatibility
    if (isset($GLOBALS['capture_render_data'])) {
        $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', "/cache/24hour/statstopisk/?u=$uri&ks=$ksa&ke=$kea");
        return;
    } else {
        return $app->redirect("/cache/24hour/statstopisk/?u=$uri&ks=$ksa&ke=$kea");
    }
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

// Handle render for compatibility
if (isset($GLOBALS['capture_render_data'])) {
    $GLOBALS['render_template'] = 'components/big_top_list.html';
    $GLOBALS['render_data'] = $ret;
    return;
} else {
    $app->render('components/big_top_list.html', $ret);
}
