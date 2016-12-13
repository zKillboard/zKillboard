<?php

if (User::isLoggedIn() == false) {
    header('HTTP/1.1 204 No Content');
    exit();
}

$app->render('components/nav-tracker.html');
