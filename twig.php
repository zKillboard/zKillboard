<?php

$currentTime = date("YmdHi");

// Load Twig globals
$app->view(new \Slim\Views\Twig());

// Setup Twig
$view = $app->view();
$view->parserOptions = array('debug' => $twigDebug, 'cache' => $twigCache);

$twig = $app->view()->getEnvironment();

// Check SSO values
$ssoCharacterID = @$_SESSION['characterID'];
if ($ssoCharacterID > 0) {
    $key = 'login:'.$ssoCharacterID.':'.session_id();
    $refreshToken = $redis->get("$key:refreshToken");
    $scopes = $redis->get("$key:scopes");
    if ($refreshToken != null) {
        $twig->addGlobal('characterID', (int) $ssoCharacterID);
    } else {
        unset($_SESSION['characterID']);
    }
    if ($scopes != null) {
        $twig->addGlobal('scopes', explode(' ', $scopes));
    }
} else {
    $twig->addGlobal('characterID', 0);
}

// Theme
$accountBalance = 0;
$userShowAds = true;
if ($ssoCharacterID > 0) {
    $info = User::getUserInfo();
    $adFreeUntil = (int) @$info['adFreeUntil']; 
    $userShowAds = ($adFreeUntil < time());
}

$uri = $_SERVER['REQUEST_URI'];
$explode = explode('/', $uri);
$expager = explode('/', $uri);

foreach ($expager as $key => $ex) {
    if (in_array($ex, array('page'))) {
        unset($expager[$key]);
        unset($expager[$key + 1]);
    }
}

foreach ($explode as $key => $ex) {
    if (in_array($ex, array('year', 'month', 'page'))) {
        // find the key for the page array
        unset($explode[$key]);
        unset($explode[$key + 1]);
    }
}

$requestUri = implode('/', $expager);
if (strlen($requestUri) == 0 || substr($requestUri, -1) != '/') {
    $requestUri .= '/';
}
$twig->addGlobal('requestUriPager', $requestUri);
//$twig->addGlobal('actualURI', $actualURI);
$twig->addGlobal('actualURI', $requestUri);
$twig->addGlobal('partial', ("/partial/" === substr($uri, 0, 9)));

// Twig globals
$twig->addGlobal('image_server', $imageServer);
$twig->addGlobal('image_character', 'https://images.evetech.net/characters/');
$twig->addGlobal('image_corporation','https://images.evetech.net/corporations/');
$twig->addGlobal('image_alliance', 'https://images.evetech.net/alliances/');
$twig->addGlobal('image_item', 'https://images.evetech.net/types/');
$twig->addGlobal('image_ship', 'https://images.evetech.net/types/');
$twig->addGlobal('esiServer', $esiServer);

if (isset($special) && isset($specialExpires) && $currentTime <= $specialExpires) {
    $twig->addGlobal('sponsoredMessage', $special);
}

$twig->addGlobal('tqStatus', $redis->get('tqStatus'));
$twig->addGlobal('tqCount', $redis->get('tqCount'));

$twig->addGlobal('apiStatus', $redis->get('tq:apiStatus'));

$twig->addGlobal('siteurl', $baseAddr);
$twig->addGlobal('fullsiteurl', $fullAddr);
$twig->addGlobal('requesturi', $_SERVER['REQUEST_URI']);

$twig->addGlobal("advertisement", Google::getAd());
$twig->addGlobal('analytics', Google::analytics($analyticsID, $analyticsName));
$twig->addGlobal('discordServer', $discordServer);

$twig->addGlobal('entityType', 'none');
$twig->addGlobal('entityID' , 0);
$twig->addGlobal('reinforced', $redis->get("zkb:reinforced") == true ? "true" : "false");
$twig->addGlobal("universeUpdating", $redis->get("zkb:universeLoaded") == "false"? "true" : "false");
$twig->addGlobal("tobefetched", $redis->get("tobefetched"));
$twig->addGlobal("tobeStatsCount", $redis->scard("queueStatsSet"));

$twig->addGlobal('showTwitch', $showTwitch);
if ($redis->get("twitch-online")) $twig->addGlobal('twitchonline', $redis->get("twitch-online"));

$twig->addGlobal('referralLink', $referralLink);

$noAdPages = array('/account/', '/information/', '/post/', '/ccp', '/ztop/');
global $showAds, $websocket;
foreach ($noAdPages as $noAdPage) {
    $showAds &= !Util::startsWith($uri, $noAdPage);
}
foreach ($adfreeURIS as $adfreeURI) {
    $showAds &= !Util::startsWith($uri, $adfreeURI);
}
$showAds &= $userShowAds;
if ($mdb->count("patreon", ['character_id' => $ssoCharacterID]) > 0) $showAds = false;
if ($ssoCharacterID == 93382481) $showAds = false;

$twig->addglobal('showAnalytics', $showAnalytics);

// User's account balance
$twig->addGlobal('accountBalance', $accountBalance);
$twig->addGlobal('adFreeMonthCost', $adFreeMonthCost);

// File timestamp
$timestamp = (int) $redis->get("timestamp");
if ($timestamp == 0) {
  $timestamp = time();
  $redis->set("timestamp", $timestamp);
}
$twig->addGlobal("timestamp", $timestamp);

$twig->addGlobal('date', date("md"));

// Display a banner?
$banner = false;
if ($banner) {
    $banner = str_replace('http://i.imgur.com/', 'https://i.imgur.com/', $banner);
    $banner = str_replace('http://imgur.com/', 'https://imgur.com/', $banner);
    //$twig->addGlobal("headerImage", $banner);
}

$twig->addGlobal('showAds', ($showAds ? 1 : 0));
$twig->addGlobal('websocket', ($websocket ? 1 : 0));
$twig->addGlobal('currentTime', $currentTime);
$_SERVER['SERVER_NAME'] = $baseAddr;

$twig->addGlobal('KillboardName', (isset($killboardName) ? $killboardName : 'zKillboard'));

// Set the style used side wide to the user selected one, or the config default
$twig->addGlobal('style', UserConfig::get('style', $style));
$twig->addGlobal('trackernotification', UserConfig::get('trackernotification', 'true'));

$twig->addExtension(new UserGlobals());

$twig->addFunction(new Twig_SimpleFunction('pageTimer', 'Util::pageTimer'));
$twig->addFunction(new Twig_SimpleFunction('queryCount', 'Util::getQueryCount'));
$twig->addFunction(new Twig_SimpleFunction('isActive', 'Util::isActive'));
$twig->addFunction(new Twig_SimpleFunction('pluralize', 'Util::pluralize'));
$twig->addFunction(new Twig_SimpleFunction('formatIsk', 'Util::formatIsk'));
$twig->addFunction(new Twig_SimpleFunction('shortNum', 'Util::formatIsk'));
$twig->addFunction(new Twig_SimpleFunction('shortString', 'Util::shortString'));
$twig->addFunction(new Twig_SimpleFunction('truncate', 'Util::truncate'));
$twig->addFunction(new Twig_SimpleFunction('chart', 'Chart::addChart'));
$twig->addFunction(new Twig_SimpleFunction('getMonth', 'Util::getMonth'));
$twig->addFunction(new Twig_SimpleFunction('getLongMonth', 'Util::getLongMonth'));
$twig->addFunction(new Twig_SimpleFunction('getMessage', 'User::getMessage'));
$twig->addFunction(new Twig_SimpleFunction('secStatusColor', 'Info::getSystemColorCode'));
$twig->addFunction(new Twig_SimpleFunction('i', 'Util::counter'));
$twig->addGlobal('version', $version);
