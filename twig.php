<?php

// Load Twig globals
$app->view(new \Slim\Views\Twig());

// Theme
$viewtheme = null;
$accountBalance = 0;
$userShowAds = true;
if (User::isLoggedIn()) {
    $accountBalance = User::getBalance(User::getUserID());
    $adFreeUntil = UserConfig::get('adFreeUntil', null);
    $userShowAds = $adFreeUntil == null ? true : $adFreeUntil <= date('Y-m-d H:i');
}

// Setup Twig
$cachepath = 'cache/templates/';
$view = $app->view();
$view->parserOptions = array(
    'debug' => ($debug ? true : false),
    'cache' => $cachepath,
);

// Load Whoops
$app->add(new \Zeuxisoo\Whoops\Provider\Slim\WhoopsMiddleware());

$twig = $app->view()->getEnvironment();

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

$twig->addGlobal('requestUriPager', implode('/', $expager));
$actualURI = implode('/', $explode);
$twig->addGlobal('actualURI', $actualURI);
$uriParams = Util::convertUriToParameters();
$twig->addGlobal('year', (isset($uriParams['year']) ? $uriParams['year'] : date('Y')));
$twig->addGlobal('month', (isset($uriParams['month']) ? $uriParams['month'] : date('m')));
// Twig globals
$twig->addGlobal('image_character', $imageServer.'Character/');
$twig->addGlobal('image_corporation', $imageServer.'Corporation/');
$twig->addGlobal('image_alliance', $imageServer.'Alliance/');
$twig->addGlobal('image_item', $imageServer.'Type/');
$twig->addGlobal('image_ship', $imageServer.'Render/');

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
$noAdPages = array('/account/', '/moderator/', '/ticket', '/register/', '/information/', '/login');
foreach ($noAdPages as $noAdPage) {
    $showAds &= !Util::startsWith($uri, $noAdPage);
    $showAds &= $userShowAds;
}
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
$banner = Db::queryField('select banner from zz_subdomains where (subdomain = :server or alias = :server)', 'banner', array(':server' => $_SERVER['SERVER_NAME']), 60);
if ($banner) {
    $banner = str_replace('http://i.imgur.com/', 'https://i.imgur.com/', $banner);
    $banner = str_replace('http://imgur.com/', 'https://imgur.com/', $banner);
    //$twig->addGlobal("headerImage", $banner);
}

$adfree = false; //Db::queryField("select count(*) count from zz_subdomains where adfreeUntil >= now() and subdomain = :server", "count", array(":server" => $_SERVER["SERVER_NAME"]), 60);
$adfree |= false; //Db::queryField("select count(*) count from zz_subdomains where adfreeUntil >= now() and alias = :server", "count", array(":server" => $_SERVER["SERVER_NAME"]), 60);
if ($adfree) {
    $twig->addGlobal('showAds', false);
} else {
    $twig->addGlobal('showAds', $showAds);
}
$_SERVER['SERVER_NAME'] = 'zkillboard.com';
Subdomains::getSubdomainParameters($_SERVER['SERVER_NAME']);

$twig->addGlobal('KillboardName', (isset($killboardName) ? $killboardName : 'zKillboard'));

// Set the style used side wide to the user selected one, or the config default
$twig->addGlobal('style', UserConfig::get('style', $style));

// Detect mobile devices
$detect = new Mobile_Detect();
$twig->addGlobal('isMobile', ($detect->isMobile() ? true : false));
$twig->addGlobal('isTablet', ($detect->isTablet() ? true : false));

$twig->addExtension(new UserGlobals());

$twig->addFunction(new Twig_SimpleFunction('pageTimer', 'Util::pageTimer'));
$twig->addFunction(new Twig_SimpleFunction('queryCount', 'Db::getQueryCount'));
$twig->addFunction(new Twig_SimpleFunction('isActive', 'Util::isActive'));
$twig->addFunction(new Twig_SimpleFunction('pluralize', 'Util::pluralize'));
$twig->addFunction(new Twig_SimpleFunction('calcX', 'Util::calcX'));
$twig->addFunction(new Twig_SimpleFunction('calcY', 'Util::calcY'));
$twig->addFunction(new Twig_SimpleFunction('formatIsk', 'Util::formatIsk'));
$twig->addFunction(new Twig_SimpleFunction('shortNum', 'Util::formatIsk'));
$twig->addFunction(new Twig_SimpleFunction('shortString', 'Util::shortString'));
$twig->addFunction(new Twig_SimpleFunction('truncate', 'Util::truncate'));
$twig->addFunction(new Twig_SimpleFunction('chart', 'Chart::addChart'));
$twig->addFunction(new Twig_SimpleFunction('getMonth', 'Util::getMonth'));
$twig->addFunction(new Twig_SimpleFunction('getLongMonth', 'Util::getLongMonth'));

// Information dropdown
$links = Util::informationPages();
$navInfo = array();
foreach ($links as $key => $data) {
    if (count($data) >= 2) {
        // Multi menu

        foreach ($data as $subData) {
            $navInfo[$key][] = $key.'/'.$subData['name'];
        }
    } else {
        $navInfo[$key] = $data[0]['name'];
    }
}
$twig->addGlobal('navInfo', $navInfo);

// IGB
$igb = false;
if (stristr(@$_SERVER['HTTP_USER_AGENT'], 'EVE-IGB')) {
    $igb = true;
}
$twig->addGlobal('eveigb', $igb);
