<?php

global $mdb, $redis, $uri;

$validTopTypes = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'locationID'];

$bypass = strpos($uri, "/bypass/") !== false;
$params = URI::validate($app, $uri, ['e' => !$bypass, 'u' => true, 't' => true, 'ks' => !$bypass, 'ke' => !$bypass]);

$epoch = (int) @$params['e'];
$uri = $params['u'];
$topType = $params['t'];
$ks = @$params['ks'];
$ke = @$params['ke'];

$sEpoch = time();
$sEpoch = $sEpoch - ($sEpoch % 900); // Update every 15 minutes

$p = Util::convertUriToParameters($uri);
$q = MongoFilter::buildQuery($p);
$ksa = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => 1]);
$kea = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => -1]);

if ($bypass || $epoch != $sEpoch || "$ks" != "$ksa" || "$ke" != "$kea") return $app->redirect("/cache/1hour/statstop10/?e=$sEpoch&u=$uri&t=$topType&ks=$ksa&ke=$kea");

$ret = [];
if (in_array($topType, $validTopTypes)) {
    $p['limit'] = 10;
    $p['pastSeconds'] = 604800;
    $p['kills'] = (strpos($uri, "/losses/") === false);
    if (strpos($uri, "/label/") === false) $p['label'] = 'pvp';

    $name = ucfirst(str_replace("solar", "", str_replace("Type", "", str_replace("ID", "", $topType))));
    $ret['topSet'] = Info::doMakeCommon("Top ${name}s", $topType, Stats::getTop($topType, $p));
}

$app->render('components/top_killer_list.html', $ret);
