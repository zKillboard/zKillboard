<?php

function handler($request, $response, $args, $container) {
    $campaigns = Campaign::publicCampaigns(100);

    return $container->get('view')->render(
        $response
            ->withHeader('Cache-Control', 'public, max-age=300, s-maxage=300')
            ->withHeader('Cache-Tag', 'www,campaigns'),
        'campaigns.pug',
        ['campaigns' => $campaigns]
    );
}
