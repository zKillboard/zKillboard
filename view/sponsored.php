<?php

global $mdb;

$result = Mdb::group("sponsored", ['killID'], ['entryTime' => ['$gte' => $mdb->now(-86400 * 7)]], [], 'isk', ['iskSum' => -1], 200);
$sponsored = [];
foreach ($result as $kill) {
    $killmail = $mdb->findDoc("killmails", ['killID' => $kill['killID']]);
    Info::addInfo($killmail);
    $killmail['victim'] = $killmail['involved'][0];
    $killmail['zkb']['totalValue'] = $kill['iskSum'];

    $sponsored[$kill['killID']] = $killmail;
}

$app->render("sponsored.html", ['sponsored' => $sponsored]);
