<?php

// Load Twig globals
$app->view(new \Slim\Views\Twig());

// Setup Twig
$view = $app->view();
$view->parserOptions = array('debug' => $twigDebug, 'cache' => $twigCache);

$twig = $app->view()->getEnvironment();

// Check SSO values
$ssoCharacterID = @$_SESSION['characterID'];
if ($ssoCharacterID != null) {
	$key = "login:" . $ssoCharacterID . ":" . session_id();
	$refreshToken = $redis->get("$key:refreshToken");
	$scopes = $redis->get("$key:scopes");
	if ($refreshToken != null) {
		$twig->addGlobal('characterID', (int) $ssoCharacterID);
	} else {
		unset($_SESSION['characterID']);
	}
	if($scopes != null) {
		$twig->addGlobal('scopes', explode(" ", $scopes));
	}
} else {
		$twig->addGlobal('characterID', 0);
}

// Theme
$viewtheme = null;
$accountBalance = 0;
$userShowAds = true;
if ($ssoCharacterID > 0) {
	$adFreeUntil = (int) $redis->hGet("user:$ssoCharacterID", "adFreeUntil");
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
if (sizeof($requestUri) == 0 || substr($requestUri, -1) != '/') {
	$requestUri .= '/';
}
$twig->addGlobal('requestUriPager', $requestUri);
$actualURI = implode('/', $explode);
$twig->addGlobal('actualURI', $actualURI);

$uriParams = [];
try {
	$uriParams = Util::convertUriToParameters();
} catch (Exception $ex) {
	header("HTTP/ 400 " . $ex->getMessage());
	die();
}
$uriParams = Util::convertUriToParameters();

$twig->addGlobal('year', (isset($uriParams['year']) ? $uriParams['year'] : date('Y')));
$twig->addGlobal('month', (isset($uriParams['month']) ? $uriParams['month'] : date('m')));
// Twig globals
$twig->addGlobal('image_character', $imageServer.'Character/');
$twig->addGlobal('image_corporation', $imageServer.'Corporation/');
$twig->addGlobal('image_alliance', $imageServer.'Alliance/');
$twig->addGlobal('image_item', $imageServer.'Type/');
$twig->addGlobal('image_ship', $imageServer.'Render/');

$twig->addGlobal('tqStatus', $redis->get('tqStatus'));
$twig->addGlobal('tqCount', $redis->get('tqCount'));

$twig->addGlobal('crestStatus', $redis->get('tq:crestStatus'));
$twig->addGlobal('crestServer', $crestServer);

$twig->addGlobal('siteurl', $baseAddr);
$twig->addGlobal('fullsiteurl', $fullAddr);
$twig->addGlobal('requesturi', $_SERVER['REQUEST_URI']);
$twig->addGlobal('topad', Google::ad($topCaPub, $topAdSlot, $adWidth = 728, $adHeight = 90));
$twig->addGlobal('bottomad', Google::ad($bottomCaPub, $bottomAdSlot, $adWidth = 728, $adHeight = 90));
$twig->addGlobal('mobiletopad', Google::ad($topCaPub, $topAdSlot, $adWidth = 320, $adHeight = 50));
$twig->addGlobal('mobilebottomad', Google::ad($bottomCaPub, $bottomAdSlot, $adWidth = 320, $adHeight = 50));
$twig->addGlobal('igbtopad', Google::ad($topCaPub, $topAdSlot, $adWidth = 728, $adHeight = 90));
$twig->addGlobal('igbbottomad', Google::ad($bottomCaPub, $bottomAdSlot, $adWidth = 728, $adHeight = 90));
$twig->addGlobal('analytics', Google::analytics($analyticsID, $analyticsName));
$disqus &= UserConfig::get('showDisqus', true);
$twig->addGlobal('disqusLoad', $disqus);
$noAdPages = array('/account/', '/ticket', '/information/', '/post/');
foreach ($noAdPages as $noAdPage) {
	$showAds &= !Util::startsWith($uri, $noAdPage);
}
$showAds &= $userShowAds;

$twig->addglobal('showAnalytics', $showAnalytics);
if ($disqus) {
	$twig->addGlobal('disqusShortName', $disqusShortName);
}
if ($disqusSSO) {
	$twig->addglobal('disqusSSO', Disqus::init());
}

// User's account balance
$twig->addGlobal('accountBalance', $accountBalance);
$twig->addGlobal('adFreeMonthCost', $adFreeMonthCost);

// Display a banner?
$banner = false;
if ($banner) {
	$banner = str_replace('http://i.imgur.com/', 'https://i.imgur.com/', $banner);
	$banner = str_replace('http://imgur.com/', 'https://imgur.com/', $banner);
	//$twig->addGlobal("headerImage", $banner);
}

$twig->addGlobal('showAds', $showAds);
$_SERVER['SERVER_NAME'] = 'zkillboard.com';
//Subdomains::getSubdomainParameters($_SERVER['SERVER_NAME']);

$twig->addGlobal('KillboardName', (isset($killboardName) ? $killboardName : 'zKillboard'));

// Set the style used side wide to the user selected one, or the config default
$twig->addGlobal('style', UserConfig::get('style', $style));

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

// IGB
$igb = false;
if (stristr(@$_SERVER['HTTP_USER_AGENT'], 'EVE-IGB')) {
	$igb = true;
}
$twig->addGlobal('eveigb', $igb);
