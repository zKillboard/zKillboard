<?php

function handler($request, $response, $args, $container) {
    $uid = (string) ($args['uid'] ?? '');
    $campaign = Campaign::find($uid);
    if (!Campaign::canView($campaign)) {
        $response->getBody()->write('<div class="alert alert-warning mb-0">Campaign not found.</div>');
        return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    $part = (string) ($args['part'] ?? ($request->getQueryParams()['part'] ?? ''));
    $swapped = str_ends_with($request->getUri()->getPath(), '/swap/');
    if ($swapped) $campaign = Campaign::swapSides($campaign);
    $group = (string) ($request->getQueryParams()['group'] ?? '');
    if (!isset(Campaign::topGroups()[$group])) $group = '';

    $env = $container->get('view')->getEnvironment();
    $html = '';
    switch ($part) {
        case 'stats':
            $html = $env->render('components/campaign_side_stats.pug', ['campaignSideStats' => Campaign::getSideStats($campaign)]);
            break;
        case 'victims':
            $html = $env->render('components/campaign_top_sets.pug', ['topSets' => Campaign::getTopSets($campaign, true, $group)]);
            break;
        case 'attackers':
            $html = $env->render('components/campaign_top_sets.pug', ['topSets' => Campaign::getTopSets($campaign, false, $group)]);
            break;
        case 'kills':
            $html = $env->render('components/war_kill_list.pug', [
                'killList' => Campaign::getKillIDs($campaign),
                'killListTitle' => 'Campaign Killmails',
                'showPager' => 'false',
                'pager' => false,
                'pageType' => 'campaign',
                'killListRowV2AttackerIDs' => Campaign::sideIDs($campaign, 'attackers'),
            ]);
            break;
        default:
            $html = '<div class="alert alert-warning mb-0">Campaign section not found.</div>';
            $response = $response->withStatus(404);
    }

    $cacheTime = Campaign::RESULT_CACHE_SECONDS;
    $cacheControl = (($campaign['public'] ?? true) === true) ? "public, max-age=$cacheTime, s-maxage=$cacheTime" : "private, max-age=$cacheTime";
    $response->getBody()->write($html);
    return $response
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withHeader('Cache-Control', $cacheControl)
        ->withHeader('Cache-Tag', "www,campaign,campaign:$uid,campaign:$uid:$part" . ($group != '' ? ":$group" : ""));
}
