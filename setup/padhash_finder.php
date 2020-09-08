<?php

require_once "../init.php";


$result = $mdb->getCollection("killmails")->aggregate(
        [
        ['$match' => [ 'padhash' => ['$ne' => null ], 'labels' => 'pvp' ]],
        [ '$group' => [ '_id' => '$padhash', 'count' => [ '$sum' => 1]  ]],
        ['$match' => [ 'count' => [ '$gt' => 5 ]]],
        //['$sort'=> ['count' => -1]],
        ],  ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]);


foreach ($result['result'] as $row) {
    print_r($row);
    $padhash = $row['_id'];
    if ($padhash == null) continue;
    Util::out( "$padhash");
    $count = 0;
    while (($count = $mdb->count("killmails", ['labels' => 'pvp', 'padhash' => $padhash])) > 5) {
        $doc = $mdb->findDoc("killmails", ['padhash' => $padhash, 'labels' => 'pvp']);
        $mdb->getCollection('killmails')->update(['_id' => $doc['_id']], ['$addToSet' => ['labels' => 'padding']]);
        $mdb->getCollection('killmails')->update(['_id' => $doc['_id']], ['$pull' => ['labels' => 'pvp']]);
        Util::out("$padhash $count");
    }
}
