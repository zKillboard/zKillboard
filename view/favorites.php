<?php

global $mdb;

if (!User::isLoggedIn()) {
    $kills = [];
} else {
    $userID = (int) User::getUserID();

    $killIDs = $mdb->find("favorites", ['characterID' => (int) $userID], ['killID' => -1]);
    $kills = Kills::getDetails($killIDs);
}

$app->render("favorites.html", ['kills' => $kills]);
