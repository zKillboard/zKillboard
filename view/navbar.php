<?php

global $redis, $ip, $version;

$redis->setex("validUser:$ip", 300, "true");

$ug = new UserGlobals();
$arr = $ug->getGlobals();
$etag = md5(serialize($arr) . date('YmdHmis'));
$etag = 'W/"' . $etag . '"';
header("ETag: $etag");
header("Cache-Control: private");

if (isset($GLOBALS['route_args'])) {
	global $twig;
	$GLOBALS['capture_render_data'] = $twig->render('components/nav-tracker.html', ['killsLastHour' => $redis->get("tqKillCount")]);
} else {
	$app->render('components/nav-tracker.html', ['killsLastHour' => $redis->get("tqKillCount")]);
}
