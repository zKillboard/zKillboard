<?php

require_once "../init.php";

if (date("Hi") != 1000) exit(); 

$modified = 0;

$pipeline = [
    ['$match' => [ 'labels' => 'pvp', 'padhash' => ['$ne' => null] ]],
    ['$group' => [ '_id' => '$padhash', 'count' => ['$sum' => 1] ]],
    ['$match' => [ 'count' => ['$gte' => 3] ]],
    ['$sort' => [ 'count' => -1 ]]
];
$coll = $mdb->getCollection("oneWeek");

$cursor = $coll->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]);
foreach ($cursor['result'] as $doc) {
    $padhash = $doc['_id'];
    $redis->setex("zkb:padhash:$padhash", 86400, "true");
    $r = $mdb->set("killmails", ['padhash' => $padhash, 'labels' => 'pvp'], ['reset' => true], true);
    $modified += $r['nModified'];
}
Util::out("padhash kilmails reset: $modified");
