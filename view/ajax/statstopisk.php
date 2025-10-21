<?php

global $mdb, $redis, $uri;

$bypass = strpos($uri, "/bypass/") !== false;
$params = URI::validate($app, $uri, ['u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);

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

if ($bypass || "$ks" != "$ksa" || "$ke" != "$kea") return $app->redirect("/cache/24hour/statstopisk/?u=$uri&ks=$ksa&ke=$kea");

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

$app->render('components/big_top_list.html', $ret);
