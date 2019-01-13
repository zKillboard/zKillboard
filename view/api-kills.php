<?php

header('HTTP/1.1 202 Request being processed');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
$app->contentType('application/json; charset=utf-8');

if ($page > 20) {
    echo json_encode([]);
    die();
}

$kills = KillCategories::getKills($type, $page, true);

$killIDs = [];
foreach ($kills as $id=>$kill) {
    $killIDs["$id"] = $kill['zkb']['hash'];
}

echo json_encode($killIDs);
