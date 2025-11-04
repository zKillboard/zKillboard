<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $result = Mdb::group("sponsored", ['killID'], ['entryTime' => ['$gte' => $mdb->now(-86400 * 7)]], [], 'isk', ['iskSum' => -1], 200);
    $sponsored = [];
    foreach ($result as $kill) {
        if ($kill['iskSum'] <= 0) continue;
        $killmail = $mdb->findDoc("killmails", ['killID' => $kill['killID']]);
        Info::addInfo($killmail);
        $killmail['victim'] = $killmail['involved'][0];
        $killmail['zkb']['totalValue'] = $kill['iskSum'];

        $sponsored[$kill['killID']] = $killmail;
    }

    return $container->get('view')->render($response, "sponsored.html", ['sponsored' => $sponsored]);
}
