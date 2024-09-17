<?php

global $mdb, $redis, $uri;

$params = URI::validate($app, $uri, ['k' => true]);
$k = $params['k'];
$killID = (int) $k;

if (strpos($uri, "/bypass/") !== false || "$k" != "$killID") return $app->redirect("/cache/1hour/badges/?k=$killID");

$badges = [
    [
        'url' => 'https://www.twitch.tv/vinnegar_dooshay',
        'query' => ['characterID' => 96847792, 'isVictim' => false, 'label' => 'loc:w-space'],
        'victim' => true,
        'image' => '/img/badges/dirtbag.png',
        'title' => 'Dirtbag!'
    ],
    [
        'url' => 'https://www.twitch.tv/schadsquatch',
        'query' => ['characterID' => 2119027366, 'isVictim' => false],
        'victim' => true,
        'image' => '/img/badges/schadeck.png',
        'title' => 'Squatch\'ed'
    ],
    [
        'url' => 'https://www.davearcher.live/',
        'query' => ['characterID' => 96891007, 'isVictim' => false],
        'victim' => true,
        'image' => '/img/badges/davearcher.png',
        'title' => 'Dave Archer Live'
    ],
    [
        'url' => 'https://www.twitch.tv/rushlock',
        'query' => ['characterID' => 275909629, 'isVictim' => false, 'groupID' => 420],
        'victim' => true,
        'image' => '/img/badges/rushlock.png',
        'title' => 'Wellness Check!'
    ],
    [
        'url' => 'https://www.twitch.tv/gh0stiegaming',
        'query' => ['characterID' => 2115503421, 'isVictim' => false],
        'victim' => true,
        'image' => '/img/badges/ghostie.png',
        'title' => 'Ghostie Gaming'
    ],
    [
        'url' => 'https://www.twitch.tv/bushkittyoneshot',
        'query' => ['characterID' => 2118601027, 'isVictim' => false],
        'victim' => true,
        'image' => '/img/badges/bushkitty.png',
        'title' => 'Bush Kitty',
    ],
    [
        'url' => 'https://www.twitch.tv/anarckos',
        'query' => ['characterID' => 249261834, 'isVictim' => false],
        'victim' => true,
        'image' => '/img/badges/anarckos.png',
        'title' => 'assimilated by the mothership',
    ],
];


$render = [];
foreach ($badges as $badge) {
    $badgeQuery = $badge['query'];
    $thisKM = ['killID' => $killID];
    $query = ['$and' => [
                MongoFilter::buildQuery($badgeQuery),
                MongoFilter::buildQuery($thisKM)
                ]
            ];
    $doc = $mdb->findDoc("killmails", $query);
    if ($doc != null) $render[] = $badge;
}

if (sizeof($render)) $app->render("components/badges.html", ['badges' => $render]);
