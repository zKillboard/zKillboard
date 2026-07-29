<?php

function handler($request, $response, $args, $container) {
    $uid = (string) ($args['uid'] ?? '');
    $campaign = Campaign::find($uid);
    if (!Campaign::canView($campaign)) {
        return $container->get('view')->render($response->withStatus(404)->withHeader('Cache-Tag', 'www,error,404,campaign'), '404.pug', ['message' => 'Campaign not found.']);
    }

    $campaign = is_object($campaign) ? (array) $campaign : $campaign;
    $campaign['filters'] = Campaign::normalizeFilters($campaign['filters'] ?? []);
    $swapped = str_ends_with($request->getUri()->getPath(), '/swap/');
    $savedCampaign = $campaign;
    if ($swapped) $campaign = Campaign::swapSides($campaign);
    $pageTitle = Campaign::title($campaign);
    $ownerID = (int) ($campaign['userID'] ?? 0);
    $ownerName = (string) ($campaign['ownerName'] ?? '');
    $data = [
        'campaign' => $campaign,
        'campaignUrl' => "/campaign/$uid/" . ($swapped ? 'swap/' : ''),
        'campaignAsyncBase' => Campaign::asearchQueryBase($savedCampaign, $swapped),
        'campaignSwapUrl' => "/campaign/$uid/" . ($swapped ? '' : 'swap/'),
        'campaignSearchUrl' => Campaign::searchUrl($savedCampaign, true),
        'victimEntities' => Campaign::sideEntities($campaign, 'victims'),
        'attackerEntities' => Campaign::sideEntities($campaign, 'attackers'),
        'pageTitle' => $pageTitle,
        'ownerID' => $ownerID,
        'ownerName' => $ownerName,
        'campaignSwapped' => $swapped,
    ];

    $cacheControl = (($campaign['public'] ?? true) === true) ? 'public, max-age=300, s-maxage=300' : 'private, max-age=60';
    return $container->get('view')->render(
        $response
            ->withHeader('Cache-Control', $cacheControl)
            ->withHeader('Cache-Tag', "www,campaign,campaign:$uid"),
        'campaign_detail.pug',
        $data
    );
}
