<?php

$kills = KillCategories::getKills($type, $page);

$killIDs = [];
foreach ($kills as $id=>$kill) {
    $killIDs["$id"] = $kill['zkb']['hash'];
}

header('HTTP/1.1 202 Request being processed');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
$app->contentType('application/json; charset=utf-8');

echo json_encode($killIDs);
