<?php

global $redis, $ip;

$redis->setex("validUser:$ip", 300, "true");

if (!User::isLoggedIn()) {
    $app->redirect('/html/loggedout.html', 302);
    exit();
}

$ug = new UserGlobals();
$arr = $ug->getGlobals();
$etag = md5(serialize($arr) . date('YmdH'));
$etag = 'W/"' . $etag . '"';
header("ETag: $etag");
header("Cache-Control: private");

if ($etag == @$_SERVER['HTTP_IF_NONE_MATCH']) {
    header("HTTP/1.1 304 Not Modified"); 
    exit();
}


$app->render('components/nav-tracker.html');
