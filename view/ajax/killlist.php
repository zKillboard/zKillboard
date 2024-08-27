<?php

global $mdb, $uri;

$params = URI::validate($app, $uri, ['s' => (strpos($uri, "/bypass/") === false), 'u' => true]);

$sequence = $params['s'];
$uri = $params['u'];

$split = split('/', $uri);
$type = @$split[1];
$id = @$split[2];
if ($type != 'label') {
    $type = "${type}ID";
    $id = (int) $id;
}
if ($type == 'shipID') $type = 'shipTypeID';
elseif ($type == 'systemID') $type = 'solarSystemID';

$app->contentType('application/json; charset=utf-8');
$stats = $mdb->findDoc("statistics", ['type' => $type, 'id' => $id]);
if ($stats == null) $stats = ['sequence' => 0];

if ($stats['sequence'] != $sequence || strpos($uri, "/bypass/") !== false) {
    $sequence = $stats['sequence'];
    return $app->redirect("/cache/1hour/killlist/?s=$sequence&u=$uri", 302);
}

$params = Util::convertUriToParameters($uri);
$kills = Kills::getKills($params, true);

echo json_encode(array_keys($kills));
