<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $cacheTag = 'www,account,campaign,create';
    if (!User::isLoggedIn()) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => 'Please log in to create campaigns.'], 401, $cacheTag);
    }

    $body = Campaign::requestBody($request);

    $filters = Campaign::filtersFromBody($body);
    $errors = Campaign::validateFilters($filters);
    if (!empty($errors)) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => implode(' ', $errors)], 400, $cacheTag);
    }

    $public = Campaign::visibilityFromBody($body);
    $userID = (int) User::getUserID();
    $now = $mdb->now();
    $doc = [
        'public' => $public,
        'userID' => $userID,
        'ownerName' => (string) Info::getInfoField('characterID', $userID, 'name'),
        'filters' => $filters,
        'filterKey' => Campaign::filterKey($filters),
        'created' => $now,
        'updated' => $now,
    ];
    $result = $mdb->insert('campaigns', $doc);
    $uid = (string) $result['_id'];
    Campaign::clearCacheTags($uid);

    ZLog::add("Created campaign $uid", $userID, true);
    return Campaign::jsonResponse($response, ['success' => true, 'uid' => $uid, 'url' => "/campaign/$uid/", 'public' => $public], 200, $cacheTag);
}
