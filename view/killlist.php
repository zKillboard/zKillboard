<?php

global $mdb, $uri;

if (strpos($uri, "s=") > strpos($uri, "u=")) return $app->notFound();
$sequence = (int) getP('s');
$uri = getP('u');
if (sizeof($_GET) > 0) return $app->notFound();

$split = split('/', $uri);
$type = @$split[1];
$id = @$split[2];
if ($type != 'label') {
    $type = "${type}ID";
    $id = (int) $id;
}

$stats = $mdb->findDoc("statistics", ['type' => $type, 'id' => $id]);
if ($stats == null) return $app->notFound();

if ($stats['sequence'] != $sequence || strpos($uri, "/bypass/") !== false) {
    $sequence = $stats['sequence'];
    return $app->redirect("/cache/1hour/killlist/?s=$sequence&u=$uri", 302);
}

$params = Util::convertUriToParameters($uri);
$kills = Kills::getKills($params, true);

$app->contentType('application/json; charset=utf-8');
echo json_encode(array_keys($kills));

function getP($key) {
    $v = @$_GET[$key];
    unset($_GET[$key]);
    return $v;
}
