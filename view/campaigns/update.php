<?php

function handler($request, $response, $args, $container) {
    $cacheTag = 'www,account,campaign,update';
    if (!User::isLoggedIn()) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => 'Please log in to update campaigns.'], 401, $cacheTag);
    }

    $uid = (string) ($args['uid'] ?? '');
    $userID = (int) User::getUserID();
    $campaign = Campaign::findOwned($uid, $userID);
    if ($campaign == null) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => 'Campaign not found.'], 404, $cacheTag);
    }

    $body = Campaign::requestBody($request);
    $filters = Campaign::filtersFromBody($body);
    $errors = Campaign::validateFilters($filters);
    if (!empty($errors)) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => implode(' ', $errors)], 400, $cacheTag);
    }

    $public = Campaign::visibilityFromBody($body, (($campaign['public'] ?? true) === true));
    if (!Campaign::updateFilters($uid, $userID, $filters, $public)) {
        return Campaign::jsonResponse($response, ['success' => false, 'message' => 'Unable to update campaign.'], 500, $cacheTag);
    }

    ZLog::add("Updated campaign $uid", $userID, true);
    return Campaign::jsonResponse($response, ['success' => true, 'uid' => $uid, 'url' => "/campaign/$uid/", 'public' => $public], 200, $cacheTag);
}
