<?php

if (!User::isLoggedIn()) {
    $app->redirect('/');
    return;
}
$userID = (int) User::getUserID();

global $mdb;

$killIDs = $mdb->find("favorites", ['characterID' => (int) $userID]);
$kills = Kills::getDetails($killIDs);

$app->render("favorites.html", ['kills' => $kills]);
