<?php

function handler($request, $response, $args, $container) {
    global $mdb;
    
    $killID = $args['killID'];
    $action = ($args['action'] == "save");

    if (!User::isLoggedIn()) {
        $response = $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Tag', "www,account,favorite,kill:$killID");
        $response->getBody()->write(json_encode(['color' => '#d0d0d0', 'message' => "You are not logged in. You need to log in to bookmark killmails."]));
        return $response;
    }
    
    $userID = (int) User::getUserID();
    $name = Info::getInfoField("characterID", $userID, "name");

    $key =  ['characterID' => $userID, 'killID' => (int) $killID];
    $mdb->remove("favorites", $key);
    
    $response = $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Tag', "www,account,favorite,kill:$killID");
    
    if ($action) {
        $mdb->insert("favorites", $key);
        $response->getBody()->write(json_encode(['color' => '#FDBC2C', 'message' => "Killmail has been added to your bookmarks."]));
        ZLog::add("$name has favorited $killID - https://zkillboard.com/kill/$killID/", $userID, true);
    } else {
        $mdb->remove("favorites", $key);
        $response->getBody()->write(json_encode(['color' => '#d0d0d0', 'message' => "Killmail has been removed from your bookmarks."]));
        ZLog::add("$name has unfavorited $killID - https://zkillboard.com/kill/$killID/", $userID, true);
    }
    
    return $response;
}

// Legacy compatibility - call handler if accessed directly
if (!function_exists('handler') || !isset($args)) {
    global $mdb;
    
    $action = ($action == "save");

    if (!User::isLoggedIn()) {
        echo json_encode(['color' => '#d0d0d0', 'message' => "You are not logged in. You need to log in to bookmark killmails."]);
        return;
    }
    $userID = (int) User::getUserID();
    $name = Info::getInfoField("characterID", $userID, "name");

    $key =  ['characterID' => $userID, 'killID' => (int) $killID];
    $mdb->remove("favorites", $key);
    if ($action) {
        $mdb->insert("favorites", $key);
        echo json_encode(['color' => '#FDBC2C', 'message' => "Killmail has been added to your Favorites."]);
        ZLog::add("$name has favorited $killID - https://zkillboard.com/kill/$killID/", $userID, true);
    } else {
        $mdb->remove("favorites", $key);
        echo json_encode(['color' => '#d0d0d0', 'message' => "Killmail has been removed from your Favorites."]);
        ZLog::add("$name has unfavorited $killID - https://zkillboard.com/kill/$killID/", $userID, true);
    }
}
