<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $twig;

    if (!User::isLoggedIn()) {
        $kills = [];
    } else {
        $userID = (int) User::getUserID();

        $killIDs = $mdb->find("favorites", ['characterID' => (int) $userID], ['killID' => -1]);
        $kills = Kills::getDetails($killIDs);
    }

    $data = ['kills' => $kills];
    return $container->view->render($response, 'favorites.html', $data);
}
