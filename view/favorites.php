<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $templates;

    $scope = $args['scope'] ?? 'character';
    $scopeFields = [
        'character' => 'characterID',
        'corporation' => 'corporationID',
        'alliance' => 'allianceID',
    ];
    if (!isset($scopeFields[$scope])) {
        return $response->withStatus(404);
    }

    $title = 'Favorites';
    if ($scope == 'corporation') $title = 'Corporation Favorites';
    if ($scope == 'alliance') $title = 'Alliance Favorites';
    if (!User::isLoggedIn()) {
        $sessID = session_id();
        $redis->setex("forward:$sessID", 900, $_SERVER['REQUEST_URI'] ?? '/account/favorites/');
        return $response->withStatus(302)->withHeader('Location', '/ccpoauth2/');
    }

    $userID = (int) User::getUserID();
    $id = $userID;
    if ($scope != 'character') {
        $info = Info::getInfo('characterID', $userID);
        if ($scope == 'corporation') $id = (int) (@$info['corporationID'] ?: @$info['corporation_id']);
        if ($scope == 'alliance') $id = (int) (@$info['allianceID'] ?: @$info['alliance_id']);
    }

    if (($scope == 'corporation' && $id <= 1999999) || ($scope == 'alliance' && $id <= 0)) {
        return $response->withStatus(403)->withHeader('Cache-Tag', 'www,account,favorites');
    }

    $killIDs = $mdb->find("favorites", ['characterID' => (int) $id], ['killID' => -1]);
    $kills = Kills::getDetails($killIDs);

    $data = ['kills' => $kills, 'title' => $title, 'scope' => $scope];
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,account,favorites'), 'favorites.pug', $data);
}
