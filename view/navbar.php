<?php

global $redis, $ip;

$redis->setex("validUser:$ip", 300, "true");

if (!User::isLoggedIn()) {
    $app->redirect('/html/loggedout.html', 302);
    exit();
}

$app->render('components/nav-tracker.html');
