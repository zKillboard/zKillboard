<?php

global $redis, $ip, $version;

$redis->setex("validUser:$ip", 300, "true");

if (!User::isLoggedIn()) {
    //session_regenerate_id();
    //$app->redirect('/html/loggedout.html?v=' . $version, 302);
    //exit();
    //$twig->setGlobal("showAds", true);
}

$ug = new UserGlobals();
$arr = $ug->getGlobals();
$etag = md5(serialize($arr) . date('YmdHmis'));
$etag = 'W/"' . $etag . '"';
header("ETag: $etag");
header("Cache-Control: private");

if ($etag == @$_SERVER['HTTP_IF_NONE_MATCH']) {
//    header("HTTP/1.1 304 Not Modified"); 
 //   exit();
}


$app->render('components/nav-tracker.html');
