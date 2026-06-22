<?php

$currentTime = date("YmdHi");

$pugOptions = [];
if ($pugCache !== false && $pugCache !== null && $pugCache !== '') {
    $pugOptions['cache'] = rtrim((string) $pugCache, '/') . '/pug/';
    if (!is_dir($pugOptions['cache'])) {
        @mkdir($pugOptions['cache'], 0777, true);
    }
}
$templates = new PugTemplateEnvironment($baseDir . 'templates/', $pugOptions);

// Create a custom view class that works with Slim 4.
class CustomPugTemplateView {
    private $templates;
    
    public function __construct($templates) {
        $this->templates = $templates;
    }
    
    public function getEnvironment() {
        return $this->templates;
    }
    
    public function render($response, $template, $data = []) {
        $body = $this->templates->render($template, $data);
        $response->getBody()->write($body);
        return $response;
    }
}

// Register the view in the Slim 4 container (DI Container)
$container->set('view', function () use ($templates) {
    return new CustomPugTemplateView($templates);
});

// Check SSO values
$ssoCharacterID = @$_SESSION['characterID'];
if ($ssoCharacterID > 0) {
    $templates->addGlobal('characterID', (int) $ssoCharacterID);
} else {
    $templates->addGlobal('characterID', -1);
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
$templates->addGlobal('requestUriPager', $requestUri);
//$templates->addGlobal('actualURI', $actualURI);
$templates->addGlobal('actualURI', $requestUri);

// Template globals
$templates->addGlobal('image_server', $imageServer);
$templates->addGlobal('image_character', 'https://images.evetech.net/characters/');
$templates->addGlobal('image_corporation','https://images.evetech.net/corporations/');
$templates->addGlobal('image_alliance', 'https://images.evetech.net/alliances/');
$templates->addGlobal('image_item', 'https://images.evetech.net/types/');
$templates->addGlobal('image_ship', 'https://images.evetech.net/types/');
$templates->addGlobal('esiServer', $esiServer);

if (@$special1 != "") {
    $templates->addGlobal('sponsoredMessage', $special);
    $templates->addGlobal('promoImage1', $promoImage1);
    $templates->addGlobal('promoURI', $promoURI);
}

if (isset($highlightMessage) && $highlightMessage != "") {
	$templates->addGlobal('highlightMessage', $highlightMessage);
	$templates->addGlobal('highlightImage', $highlightImage);
	$templates->addGlobal('highlightURI', $highlightURI);
	$templates->addGlobal('highlightClass', $highlightClass);
}

$templates->addGlobal('tqStatus', $redis->get('tqStatus'));
$templates->addGlobal('tqCount', $redis->get('tqCount'));

$templates->addGlobal('apiStatus', $redis->get('tq:apiStatus'));

$templates->addGlobal('siteurl', $baseAddr);
$templates->addGlobal('fullsiteurl', $fullAddr);
$templates->addGlobal('requesturi', $_SERVER['REQUEST_URI']);

$templates->addGlobal("advertisement", Google::getAd());
$templates->addGlobal('analytics', Google::analytics($analyticsID, $analyticsName));
$templates->addGlobal('discordServer', $discordServer);

$templates->addGlobal('entityType', 'none');
$templates->addGlobal('entityID' , 0);
$templates->addGlobal('reinforced', $redis->get("zkb:reinforced") == true ? "true" : "false");
$templates->addGlobal("universeUpdating", $redis->get("zkb:universeLoaded") == "false"? "true" : "false");
$templates->addGlobal("tobefetched", $redis->get("tobefetched"));
$templates->addGlobal("tobeStatsCount", $redis->scard("queueStatsSet"));
$templates->addGlobal("z3", $z3);

$templates->addGlobal('referralLink', $referralLink);

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

$templates->addglobal('showAnalytics', $showAnalytics);

// User's account balance
$templates->addGlobal('accountBalance', $accountBalance);
$templates->addGlobal('adFreeMonthCost', $adFreeMonthCost);

// File timestamp
$timestamp = (int) $redis->get("timestamp");
if ($timestamp == 0) {
  $timestamp = time();
  $redis->set("timestamp", $timestamp);
}
$templates->addGlobal("timestamp", $timestamp);

$templates->addGlobal('date', date("md"));

// Display a banner?
$banner = false;
if ($banner) {
    $banner = str_replace('http://i.imgur.com/', 'https://i.imgur.com/', $banner);
    $banner = str_replace('http://imgur.com/', 'https://imgur.com/', $banner);
    //$templates->addGlobal("headerImage", $banner);
}

$templates->addGlobal('showAds', ($showAds ? 1 : 0));
$templates->addGlobal('websocket', ($websocket ? 1 : 0));
$templates->addGlobal('currentTime', $currentTime);
$_SERVER['SERVER_NAME'] = $baseAddr;

$templates->addGlobal('KillboardName', (isset($killboardName) ? $killboardName : 'zKillboard'));

// Set the style used side wide to the user selected one, or the config default
$templates->addGlobal('style', UserConfig::get('style', $style));
$templates->addGlobal('trackernotification', UserConfig::get('trackernotification', 'true'));

$templates->addExtension(new UserGlobals());

$templates->addFunction('isActive', 'Util::isActive');
$templates->addFunction('pluralize', 'Util::pluralize');
$templates->addFunction('formatIsk', 'Util::formatIsk');
$templates->addFunction('shortNum', 'Util::formatIsk');
$templates->addFunction('shortString', 'Util::shortString');
$templates->addFunction('truncate', 'Util::truncate');
$templates->addFunction('chart', 'Chart::addChart');
$templates->addFunction('getMonth', 'Util::getMonth');
$templates->addFunction('getLongMonth', 'Util::getLongMonth');
$templates->addFunction('getMessage', 'User::getMessage');
$templates->addFunction('secStatusColor', 'Info::getSystemColorCode');
$templates->addFunction('i', 'Util::counter');
$templates->addGlobal('version', $version);
$templates->addGlobal('versionTime', time());
