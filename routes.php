<?php

$app->notFound(function () use ($app) {
        $app->redirect('..', 302);
        });

// Default route
$app->get('/(page/:page/)', function ($page = 1) use ($app) {
        include 'view/index.php';
        });

$app->get('/kills.html/', function ($page = 'about') use ($app) {
        die("<script type='text/javascript'>location.reload();</script>");
        });

// Map
$app->get('/map/', function () use ($app) {
        $app->render('map.html', ['showAds' => false]);
        });

//  Information about zKillboard
$app->get('/information/(:page/)', function ($page) use ($app) {
        include 'view/information.php';
        });

// Tickets
$app->map('/tickets/', function () use ($app) {
        include 'view/tickets.php';
        })->via('GET', 'POST');

$app->map('/tickets/view/:id/', function ($id) use ($app) {
        include 'view/tickets_view.php';
        })->via('GET', 'POST');

// View kills
$app->get('/kills/page/:page/', function ($page = 1) use ($app) {
        $type = null;
        include 'view/kills.php';
        });
$app->get('/kills(/:type)(/page/:page)/', function ($type = null, $page = 1) use ($app) {
        include 'view/kills.php';
        });

// View related kills
$app->get('/related/:system/:time/(o/:options/)', function ($system, $time, $options = '') use ($app) {
        include 'view/related.php';
        });

// View Battle Report
$app->get('/br/list/', function () use ($app) {
        include 'view/battle_list.php';
        });

// View Battle Report
$app->get('/br/:battleID/', function ($battleID) use ($app) {
        include 'view/battle_report.php';
        });

// View Battle Report
$app->get('/brsave/', function () use ($app) {
        include 'view/brsave.php';
        });

// View top
$app->get('/top/lasthour/', function () use ($app) {
        include 'view/lasthour.php';
        });
$app->get('/ranks/:pageType/:subType/', function ($pageType, $subType) use ($app) {
        include 'view/ranks.php';
        });
$app->get('/top(/:type)(/:page)(/:time+)/', function ($type = 'weekly', $page = null, $time = array()) use ($app) {
        include 'view/top.php';
        });

// Raw Kill Detail
$app->get('/raw/:id/', function ($id) use ($app) {
        include 'view/raw.php';
        });

// Kill Detail View
$app->get('/detail/:id(/:pageview)/', function ($id, $pageview = 'overview') use ($app) {
        $app->redirect("/kill/$id/", 301); // Permanent redirect
        die();
        });
$app->get('/kill/:id(/:pageview)/', function ($id, $pageview = 'overview') use ($app) {
        include 'view/detail.php';
        })->via('GET', 'POST');

// Sitemap
$app->get('/sitemap/', function () use ($app) {
        global $cookie_name, $cookie_time, $baseAddr;
        include 'view/sitemap.php';
        });

// Logout
$app->get('/logout/', function () use ($app) {
        global $cookie_name, $cookie_time, $baseAddr;
        include 'view/logout.php';
        });

$app->get('/account/tracker/:type/:id/:action/', function ($type, $id, $action) use ($app) {
        include 'view/account_tracker.php';
        });

// Account
$app->map('/account(/:req)(/:reqid)/', function ($req = null, $reqid = null) use ($app) {
        global $cookie_name, $cookie_time;
        include 'view/account.php';
        })->via('GET', 'POST');

// EveInfo
$app->get('/item/:id/', function ($id) use ($app) {
        global $oracleURL;
        include 'view/item.php';
        });

$app->get('/comments/', function () use ($app) {
        $app->render('/comments.html');
        });

$app->get('/api/dna(/:flags+)/', function ($flags = null) use ($app) {
        include 'view/apidna.php';
        });

$app->get('/api/stats/:type/:id/', function ($type, $id) use ($app) {
        include 'view/apistats.php';
        });

$app->get('/api/:input+', function ($input) use ($app) {
        include 'view/api.php';
        });

// Post
$app->get('/post/', function () use ($app) {
        include 'view/postmail.php';
        });
$app->post('/post/', function () use ($app) {
        include 'view/postmail.php';
        });

// Search
$app->map('/search(/:search)/', function ($search = null) use ($app) {
        include 'view/search.php';
        })->via('GET', 'POST');

// Autocomplete
$app->map('/autocomplete/', function () use ($app) {
        include 'view/autocomplete.php';
        })->via('POST');
$app->map('/autocomplete/:entityType/:search/', function ($entityType, $search) use ($app) {
        include 'view/autocomplete.php';
        })->via('GET');
$app->map('/autocomplete/:search/', function ($search) use ($app) {
        include 'view/autocomplete.php';
        })->via('GET');

// Intel
$app->get('/intel/supers/', function () use ($app) {
        include 'view/intel.php';
        });

// Sharing Crest Mails
$app->get('/crestmail/:killID/:hash/', function ($killID, $hash) use ($app) {
        include 'view/crestmail.php';
        });

// War!
$app->get('/war/:warID/', function ($warID) use ($app) {
        include 'view/war.php';
        });
$app->get('/wars/', function () use ($app) {
        include 'view/wars.php';
        });

// CREST
$app->get('/ccplogin/', function () use ($app) {
        include 'view/ccplogin.php';
        });
$app->get('/ccpcallback/', function () use ($app) {
        include 'view/ccpcallback.php';
        });
$app->get('/ccpsavefit/:killID/', function ($killID) use ($app) {
        include 'view/ccpsavefit.php';
        });

// The Overview stuff
$app->get('/:input+/', function ($input) use ($app) {
        include 'view/overview.php';
        });
