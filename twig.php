<?php

$currentTime = date("YmdHi");

// Create Twig 3 environment directly
$loader = new \Twig\Loader\FilesystemLoader($baseDir . 'templates/');
$twig = new \Twig\Environment($loader, array('debug' => $twigDebug, 'cache' => $twigCache));

// Create a custom view class that works with Slim 3
class CustomTwigView {
    private $twig;
    
    public function __construct($twig) {
        $this->twig = $twig;
    }
    
    public function getEnvironment() {
        return $this->twig;
    }
    
    public function render($response, $template, $data = []) {
        $body = $this->twig->render($template, $data);
        $response->getBody()->write($body);
        return $response;
    }
}

// Register the view in the Slim 3 container
$container = $app->getContainer();
$container['view'] = function ($c) use ($twig) {
    return new CustomTwigView($twig);
};

// Setup not found handler
$container['notFoundHandler'] = function ($c) {
    return function ($request, $response) use ($c) {
        return $response->withStatus(302)->withHeader('Location', './../');
    };
};

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
    $twig->addGlobal('characterID', -1);
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

if (@$special1 != "") {
    $twig->addGlobal('sponsoredMessage', $special);
    $twig->addGlobal('promoImage1', $promoImage1);
    $twig->addGlobal('promoURI', $promoURI);
}

if (isset($highlightMessage) && $highlightMessage != "") {
	$twig->addGlobal('highlightMessage', $highlightMessage);
	$twig->addGlobal('highlightImage', $highlightImage);
	$twig->addGlobal('highlightURI', $highlightURI);
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

$twig->addFunction(new \Twig\TwigFunction('isActive', 'Util::isActive'));
$twig->addFunction(new \Twig\TwigFunction('pluralize', 'Util::pluralize'));
$twig->addFunction(new \Twig\TwigFunction('formatIsk', 'Util::formatIsk'));
$twig->addFunction(new \Twig\TwigFunction('shortNum', 'Util::formatIsk'));
$twig->addFunction(new \Twig\TwigFunction('shortString', 'Util::shortString'));
$twig->addFunction(new \Twig\TwigFunction('truncate', 'Util::truncate'));
$twig->addFunction(new \Twig\TwigFunction('chart', 'Chart::addChart'));
$twig->addFunction(new \Twig\TwigFunction('getMonth', 'Util::getMonth'));
$twig->addFunction(new \Twig\TwigFunction('getLongMonth', 'Util::getLongMonth'));
$twig->addFunction(new \Twig\TwigFunction('getMessage', 'User::getMessage'));
$twig->addFunction(new \Twig\TwigFunction('secStatusColor', 'Info::getSystemColorCode'));
$twig->addFunction(new \Twig\TwigFunction('i', 'Util::counter'));
$twig->addGlobal('version', $version);
$twig->addGlobal('versionTime', time());
