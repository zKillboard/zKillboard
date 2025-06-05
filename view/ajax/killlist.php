<?php

global $mdb, $uri;

$bypass = strpos($uri, "/bypass/") !== false;
$params = URI::validate($app, $uri, ['s' => !$bypass, 'u' => true]);

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

$sa = (int) $stats['sequence'];
if ($bypass || "$sa" != "$sequence") {
    return $app->redirect("/cache/24hour/killlist/?s=$sa&u=$uri", 302);
}

$params = Util::convertUriToParameters($uri);
$page = (int) @$params['page'];
if ($page < 0 || $page > 20) $kills = [];
else $kills = Kills::getKills($params, true);

echo json_encode(array_keys($kills));
