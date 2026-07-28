<?php

function handler($request, $response, $args, $container) {
    $cacheTag = 'www,account,campaign,matches';
    if (!User::isLoggedIn()) {
        return Campaign::jsonResponse($response, ['success' => true, 'campaigns' => []], 200, $cacheTag);
    }

    $body = Campaign::requestBody($request);
    $filters = Campaign::filtersFromBody($body, false);
    if (!Campaign::hasSearchFilters($filters)) {
        return Campaign::jsonResponse($response, ['success' => true, 'campaigns' => []], 200, $cacheTag);
    }

    $userID = (int) User::getUserID();
    $campaigns = [];
    foreach (Campaign::matchingCampaigns($filters, 5, $userID) as $campaign) {
        $uid = (string) ($campaign['_id'] ?? '');
        if ($uid == '') continue;
        $campaigns[] = Campaign::listRow($campaign);
    }

    $editableCampaign = null;
    $campaignUID = (string) ($body['campaignUID'] ?? '');
    if ($campaignUID != '' && count(Campaign::validateFilters($filters)) == 0) {
        $campaign = Campaign::findOwned($campaignUID, $userID);
        if ($campaign != null) {
            $row = Campaign::listRow($campaign);
            $campaignKey = (string) ($campaign['filterKey'] ?? '');
            if ($campaignKey == '') $campaignKey = Campaign::filterKey($campaign['filters'] ?? []);
            $row['changed'] = Campaign::filterKey($filters) != $campaignKey && Campaign::filterKey($filters, false) != $campaignKey;
            $editableCampaign = $row;
        }
    }

    return Campaign::jsonResponse($response, ['success' => true, 'campaigns' => $campaigns, 'editableCampaign' => $editableCampaign], 200, $cacheTag);
}
