<?php

require_once "../init.php";

$coll = "ninetyDays";
$result = $mdb->getCollection($coll)->aggregate(
        [
            ['$match' => [ 'padhash' => ['$ne' => null ] ]],
            [ '$group' => [ '_id' => '$padhash', 'count' => [ '$sum' => 1]  ]],
            ['$match' => [ 'count' => [ '$gt' => 2 ]]],
            ['$sort'=> ['count' => -1]],
        ],  
        ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]
);


$total = 0;
foreach ($result as $row) {
    $padhash = $row['_id'];
    if ($padhash == null) continue;
    $count = $mdb->count($coll, ['labels' => 'pvp', 'padhash' => $padhash]);
    $total += $count;
    $mdb->getCollection("killmails")->updateMany(['padhash' => $padhash], ['$addToSet' => ['labels' => 'padding']]);
    $mdb->getCollection("ninetyDays")->updateMany(['padhash' => $padhash], ['$addToSet' => ['labels' => 'padding']]);
    $mdb->getCollection("oneWeek")->updateMany(['padhash' => $padhash], ['$addToSet' => ['labels' => 'padding']]);
}
foreach (['killmails', 'ninetyDays', 'oneWeek'] as $coll) {
    $mdb->getCollection($coll)->updateMany(['labels' => ['$all' => ['padding', 'pvp']]], ['$pull' => ['labels' => 'pvp']]);
    $mdb->getCollection($coll)->updateMany(['labels' => ['$all' => ['padding', 'solo']]], ['$pull' => ['labels' => 'solo']]);
}
Util::out("total: $total");
