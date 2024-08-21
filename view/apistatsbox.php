<?php

global $mdb, $redis;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$array = $mdb->findDoc('statistics', ['type' => $type, 'id' => (int) $id]);
if ($array == null) return $app->notFound();

if (!isset($epoch)) $epoch = 0;
$sEpoch = $array['epoch'];
if (((int) $epoch) != $sEpoch) return $app->redirect("/cache/1hour/statsbox/$type/$id/$sEpoch/");

$array['activepvp'] = (object) Stats::getActivePvpStats([$type => [$id]]);
$array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => $id]);
unset($array['info']['_id']);

$ret = [];
$ret['s-a-sd'] = (int) @$array['shipsDestroyed'];
$ret['s-a-sd-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:shipsDestroyed", $id));
$ret['s-a-sl'] = (int) @$array['shipsLost'];
$ret['s-a-sl-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:shipsLost", $id));
$ret['s-a-id'] = (int) @$array['iskDestroyed'];
$ret['s-a-id-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:iskDestroyed", $id));
$ret['s-a-il'] = (int) @$array['iskLost'];
$ret['s-a-il-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:iskLost", $id));
$ret['s-a-pd'] = (int) @$array['pointsDestroyed'];
$ret['s-a-pd-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:pointsDestroyed", $id));
$ret['s-a-pl'] = (int) @$array['pointsLost'];
$ret['s-a-pl-r'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$type:pointsLost", $id));

$ret['s-a-s-e'] = eff($ret['s-a-sd'], $ret['s-a-sl']);
$ret['s-a-i-e'] = eff($ret['s-a-id'], $ret['s-a-il']);
$ret['s-a-p-e'] = eff($ret['s-a-pd'], $ret['s-a-pl']);

$array['ret'] = $ret;
$app->contentType('application/json; charset=utf-8');
echo json_encode($ret);

function eff($a, $b) {
    $t = $a + $b;
    if ($t == 0) return "-";
    return ($a / $t) * 100;
}
