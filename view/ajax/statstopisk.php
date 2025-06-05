<?php

global $mdb, $redis, $uri;

$bypass = strpos($uri, "/bypass/") !== false;
$params = URI::validate($app, $uri, ['u' => true, 'ks' => !$bypass, 'ke' => !$bypass]);

$uri = $params['u'];
$ks = @$params['ks'];
$ke = @$params['ke'];

$epoch = time();
$epoch = $epoch - ($epoch % 900);

$p = Util::convertUriToParameters($uri);
$q = MongoFilter::buildQuery($p);
$ksa = getKillID($uri, $q, 1, $epoch);
$kea = getKillID($uri, $q, -1, $epoch);

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

if ($ksa == 0) echo "";
else $app->render('components/big_top_list.html', $ret);

function getKillID($uri, $q, $sort, $epoch) {
    global $redis, $mdb;
    $key = "stats:tops:$sort:$epoch:$uri";
    $killID = $redis->get($key);
    if ($killID == null) {
        $killID = (int) $mdb->findField("oneWeek", "killID", $q, ['killID' => $sort]);
        $redis->setex($key, 910, $killID);
    }
    return (int) $killID;
}
