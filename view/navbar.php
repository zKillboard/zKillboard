<?php

if (!User::isLoggedIn()) {
    $app->redirect('/html/loggedout.html', 302);
    exit();
}

$app->render('components/nav-tracker.html');
