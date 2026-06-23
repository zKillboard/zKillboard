<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $templates;

    if (!User::isLoggedIn()) {
        $kills = [];
    } else {
        $userID = (int) User::getUserID();

        $killIDs = $mdb->find("favorites", ['characterID' => (int) $userID], ['killID' => -1]);
        $kills = Kills::getDetails($killIDs);
    }

    $data = ['kills' => $kills];
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,account,favorites'), 'favorites.pug', $data);
}
