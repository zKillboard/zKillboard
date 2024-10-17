<?php

$app->notFound(function () use ($app) {
    http_response_code(404);
        });

$app->get('/information/', function() use ($app) {
        $app->redirect("/information/about/", 302);
    });
$app->get('/faq/', function() use ($app) {
        $app->redirect("/information/faq/", 302);
    });

$app->get('/challenge/', function() use ($app) {
        include "view/challenge.php";
    });

$app->get('/cache/1hour/publift/:type/', function($type) use ($app) {
        global $publift;
        echo "<div data-fuse='" . @$publift[$type] . "'></div>";
    });
$app->get('/cache/1hour/google/', function() use ($app) {
        $mobile = false;
        include "view/google.php";
    });
$app->get('/google/', function() use ($app) {
        $app->redirect('/cache/1hour/google/', 302);
        return;
    });
$app->get('/google/:mobile/', function() use ($app) {
        $app->redirect('/cache/1hour/google/', 302);
        return;
    });

$app->get('/(page/:page/)', function ($page = 1) use ($app) {
        include 'view/index.php';
        });
$app->get('/partial/(page/:page/)', function ($page = 1) use ($app) {
        include 'view/index.php';
        });

// Map
$app->get('/map2020/', function () use ($app) {
        $app->render('map.html');
        });

//  Information about zKillboard
$app->get('/information/(:page/)', function ($page) use ($app) {
        include 'view/information.php';
        });

$app->get('/account/favorites/', function() use ($app) {
        include 'view/favorites.php';
        });
$app->post('/account/favorite/:killID/:action/', function($killID, $action) use ($app) {
        include 'view/favorite_modify.php';
        });

$app->get('/related/:system/:time', function ($system, $time) use ($app) {
        $app->redirect("/related/$system/$time/");
        });
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

// View Battle Report
$app->get('/bigisk/', function () use ($app) {
        include 'view/bigisk.php';
        });

$app->get('/:type/ranks/:kl/:epoch/:page/', function($type, $kl, $epoch, $page) use ($app) {
        include 'view/typeRanks.php';
});
/*$app->get('/cache/bypass/:type/ranks/:kl/:epoch/:page/', function($type, $kl, $epoch, $page) use ($app) {
        include 'view/typeRanks.php';
});*/

// View top
$app->get('/top/lasthour/:type/', function ($type) use ($app) {
        include 'view/lasthour.php';
        });
$app->get('/top(/:type)(/:page)(/:time+)/', function ($type = 'weekly', $page = null, $time = array()) use ($app) {
        include 'view/top.php';
        });

// Raw Kill Detail
/*$app->get('/raw/:id/', function ($id) use ($app) {
  include 'view/raw.php';
  });*/

$app->get('/detail/:id(/:pageview)/', function ($id, $pageview = 'overview') use ($app) {
        $app->redirect("/kill/$id/", 302);
        });
// Kill Detail View
$app->get('/partial/kill/:id(/:pageview)/', function ($id, $pageview = '') use ($app) {
        include 'view/detail.php';
        });
$app->get('/kill/:id/', function ($id, $pageview = '') use ($app) {
        include 'view/detail.php';
        });

// Logout
$app->get('/account/logout/', function () use ($app) {
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

$app->get('/api/recentactivity/', function () use ($app) { include 'view/api/recentactivity.php'; });

$app->get('/api/supers/', function () use ($app) {
        include 'view/intel.php';
        });
$app->get('/api/related/:system/:time/', function ($system, $time) use ($app) {
        $mc = RelatedReport::generateReport($system, $time, "[]");
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        $app->contentType('application/json; charset=utf-8');
        echo json_encode($mc, JSON_PRETTY_PRINT);
        });

$app->get('/api/history/:date/', function ($date) use ($app) {
        header("Location: /api/history/$date.json", 302);
        return;
        });

$app->get('/api/stats/:type/:id/', function ($type, $id) use ($app) {
        include 'view/apistats.php';
        });

$app->get('/scanalyzer/', function () use ($app) { include 'view/scanalyzer.php'; });

$app->get('/cache/bypass/stats/', function () use ($app) { include 'view/ajax/stats.php'; });
$app->get('/cache/1hour/stats/', function () use ($app) { include 'view/ajax/stats.php'; });
$app->get('/cache/24hour/stats/', function () use ($app) { include 'view/ajax/stats.php'; });

$app->get('/cache/1hour/killlist/', function () use ($app) { include 'view/ajax/killlist.php'; });
$app->get('/cache/24hour/killlist/', function () use ($app) { include 'view/ajax/killlist.php'; });

$app->get('/cache/1hour/statstop10/', function () use ($app) { include 'view/ajax/statstop10.php'; });
$app->get('/cache/24hour/statstop10/', function () use ($app) { include 'view/ajax/statstop10.php'; });

$app->get('/cache/1hour/statstopisk/', function () use ($app) { include 'view/ajax/statstopisk.php'; });
$app->get('/cache/24hour/statstopisk/', function () use ($app) { include 'view/ajax/statstopisk.php'; });

$app->get('/cache/1hour/badges/', function () use ($app) { include 'view/ajax/badges.php'; });

$app->get('/api/prices/:id/', function ($id) use ($app) {
        include 'view/apiprices.php';
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

// Advanced Search
$app->map('/asearch/', function ($search = null) use ($app) {
        include 'view/asearch.php';
        })->via('GET');
$app->map('/asearchquery/', function ($search = null) use ($app) {
        include 'view/asearchquery.php';
        })->via('GET');
$app->map('/asearchinfo/', function ($type = null, $id = null) use ($app) {
        include 'view/asearchinfo.php';
        })->via('GET');

$app->get('/cache/1hour/autocomplete/', function () use ($app) {
        include 'view/search2020.php';
        });

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
$app->get('/api/supers/', function () use ($app) {
        include 'view/intel.php';
        });
$app->get('/intel/supers/', function () use ($app) {
        include 'view/intel.php';
        });

// Sharing Crest Mails
$app->get('/crestmail/:killID/:hash/', function ($killID, $hash) use ($app) {
        include 'view/crestmail.php';
        });

// War!
$app->get('/war/eligible/', function() use ($app) {
    include 'view/war_eligible.php';
});
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

// EVE Online OAUTH2
$app->get('/ccpoauth2/', function()  use ($app) {
        include 'view/ccpoauth2.php';
    });

// EVE Online OAUTH2
$app->get('/ccpoauth2-360noscope/', function()  use ($app) {
        include 'view/ccpoauth2-noscopes.php';
    });

// Patreon
$app->get('/cache/bypass/login/patreon/', function () use ($app) {
        include 'view/patreonlogin.php';
        });
$app->get('/cache/bypass/login/patreonauth/', function () use ($app) {
        include 'view/patreonauth.php';
        });

// Twitch
$app->get('/cache/bypass/login/twitch/', function () use ($app) {
        include 'view/twitchlogin.php';
        });
$app->get('/cache/bypass/login/twitchauth/', function () use ($app) {
        include 'view/twitchauth.php';
        });

$app->get('/navbar/', function () use ($app) {
        include 'view/navbar.php';
        });

$app->get('/ztop/', function () use ($app) {
        $app->render("ztop.html", ['showAds' => false]);
        });

// Sponsor killmail adjustments
$app->get('/sponsor/:type/:killID/(:value/)', function ($type, $killID, $value = 0) use ($app) {
        include 'view/sponsor.php';
        });
// Sponsored killmails
$app->get('/kills/sponsored/', function () use ($app) {
        include 'view/sponsored.php';
        });

$app->get('/cache/bypass/comment/:pageID/:commentID/up/', function ($pageID, $commentID) use ($app) {
        include 'view/comments-up.php';
        });

$app->get('/cache/1hour/killlistrow/:killID/', function ($killID) use ($app) { include 'view/killlistrow.php'; });
$app->get('/cache/24hour/killlistrow/:killID/', function ($killID) use ($app) { include 'view/killlistrow.php'; });

// The Overview stuff
/*$app->get('/partial/:input+/', function ($input) use ($app) {
        include 'view/overview.php';
        });*/
$app->get('/:input+/', function ($input) use ($app) {
        include 'view/overview.php';
        });
