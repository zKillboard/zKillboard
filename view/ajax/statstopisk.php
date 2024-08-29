<?php

global $mdb, $redis, $uri;

$bypass = strpos($uri, "/bypass/") !== false;
$params = URI::validate($app, $uri, ['e' => !$bypass, 'u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);

$epoch = (int) @$params['e'];
$uri = $params['u'];
$ks = @$params['ks'];
$ke = @$params['ke'];

$sEpoch = time();
$sEpoch = $sEpoch - ($sEpoch % 900); // Update every 15 minutes

$p = Util::convertUriToParameters($uri);
$q = MongoFilter::buildQuery($p);
$ksa = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => 1]);
$kea = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => -1]);

if ($bypass || $epoch != $sEpoch || "$ks" != "$ksa" || "$ke" != "$kea") return $app->redirect("/cache/1hour/statstopisk/?e=$sEpoch&u=$uri&ks=$ksa&ke=$kea");

$ret = [];
$p['limit'] = 10;
$p['pastSeconds'] = 604800;
$p['kills'] = (strpos($uri, "/losses/") === false);
if (strpos($uri, "/label/") === false) $p['label'] = 'pvp';

$p['limit'] = 6;
$ret['topSet'] = Stats::getTopIsk($p);

$app->render('components/big_top_list.html', $ret);
