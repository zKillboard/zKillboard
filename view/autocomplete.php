<?php

global $redis;

$imageMap = ['typeID' => 'Type/%1$d_32.png', 'characterID' => 'Character/%1$d_32.jpg', 'corporationID' => 'Corporation/%1$d_32.png', 'allianceID' => 'Alliance/%1$d_32.png', 'factionID' => 'Alliance/%1$d_32.png'];

if ($app->request()->isPost()) {
    $search = $app->request()->post('query');
}

if (!(isset($entityType))) {
    $entityType = null;
}

$result = zkbSearch::getResults($search, $entityType);

// Declare out json return type
$app->contentType('application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

echo json_encode($result);
