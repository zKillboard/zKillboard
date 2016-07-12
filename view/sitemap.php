<?php

$data = array();

$data['map'] = array(
        'Realtime Map' => 'https://zkillboard.com/map/',
        );

$data['kills'] = array(
        'All Kills' => '',
        'Big Kills' => 'bigkills',
        'Awox' => 'awox',
        'W-space' => 'w-space',
        'Solo' => 'solo',
        '5b+' => '5b',
        '10b+' => '10b',
        'Capitals' => 'capitals',
        'Freighters' => 'freighters',
        'Supers' => 'supers',
        'Dust - All Kills' => 'dust',
        'Dust - Vehicles' => 'dust_vehicles',
        );

$data['intel'] = array(
        'Supers' => 'supers',
        );

$data['top'] = array(
        'Last Hour' => 'lasthour',
        );

$data['ranks'] = array(
        'Recent Kills' => 'recent/killers',
        'Recent Losers' => 'recent/losers',
        'Alltime Killers' => 'alltime/killers',
        'Alltime Losers' => 'alltime/losers',
        );

$data['post'] = array(
        'Post Kills' => '',
        );

$data['support'] = array(
        'Tickets' => '/tickets/',
        'Live Chat' => '/livechat',
        );

$data['information'] = array(
        'About' => 'about',
        'Killmails' => 'killmails',
        'Legal' => 'legal',
        'Payments' => 'payments',
        'API' => 'https://neweden-dev.com/ZKillboard_API',
        );

$app->render('sitemap.html', array('data' => $data));
