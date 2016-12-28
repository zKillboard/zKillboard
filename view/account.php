<?php

global $mdb;

if (!User::isLoggedIn()) {
    $app->redirect('/ccplogin', 302);
    die();
}

$userID = User::getUserID();
$key = 'sitesettings';
$error = '';

$bannerUpdates = array();
$aliasUpdates = array();

if (isset($req)) {
    $key = $req;
}

global $twig, $adFreeMonthCost, $baseAddr;
if ($_POST) {
    $keyid = Util::getPost('keyid');
    $vcode = Util::getPost('vcode');
    $label = Util::getPost('label');

    // Apikey stuff
    if (isset($keyid) || isset($vcode)) {
        $error = Api::addKey($keyid, $vcode, $label);
    }

    $deletekeyid = Util::getPost('deletekeyid');
    $deleteentity = Util::getPost('deleteentity');
    // Delete an apikey
    if (isset($deletekeyid) && !isset($deleteentity)) {
        $error = Api::deleteKey($deletekeyid);
    }

    // Theme
    $theme = Util::getPost('theme');
    if (isset($theme)) {
        UserConfig::set('theme', $theme);
        $app->redirect($_SERVER['REQUEST_URI']);
    }

    // Style
    $style = Util::getPost('style');
    if (isset($style)) {
        UserConfig::set('style', $style);
        $app->redirect($_SERVER['REQUEST_URI']);
    }

    // Disqus
    $showDisqus = Util::getPost('showDisqus');
    if (isset($showDisqus)) {
        UserConfig::set('showDisqus', $showDisqus);
        $app->redirect($_SERVER['REQUEST_URI']);
    }

    $timeago = Util::getPost('timeago');
    if (isset($timeago)) {
        UserConfig::set('timeago', $timeago);
    }

    $ddcombine = Util::getPost('ddcombine');
    if (isset($ddcombine)) {
        UserConfig::set('ddcombine', $ddcombine);
    }

    $ddmonthyear = Util::getPost('ddmonthyear');
    if (isset($ddmonthyear)) {
        UserConfig::set('ddmonthyear', $ddmonthyear);

    }

    $subdomain = Util::getPost('subdomain');
    if ($subdomain != null) {
        $banner = Util::getPost('banner');
        $alias = Util::getPost('alias');
        $bannerUpdates = array("$subdomain" => $banner);
        if (strlen($alias) == 0 || (strlen($alias) >= 6 && strlen($alias) <= 64)) {
            $aliasUpdates = array("$subdomain" => $alias);
        }
        // table is updated if user is ceo/executor in code thta loads this information below
    }
}

$data['entities'] = User::getUserTrackerData();

// Theme
$theme = UserConfig::get('theme', 'zkillboard');
$data['themesAvailable'] = [];
$data['currentTheme'] = $theme;

// Style
$data['stylesAvailable'] = Util::availableStyles();
$data['currentStyle'] = UserConfig::get('style');

$data['apiKeys'] = Api::getKeys($userID);
$data['apiSsoKeys'] = Api::getSsoKeys($userID);
$data['apiChars'] = Api::getCharacters($userID);
$charKeys = Api::getCharacterKeys($userID);
$charKeys = Info::addInfo($charKeys);
$data['apiCharKeys'] = $charKeys;
$data['userInfo'] = User::getUserInfo();
$data['timeago'] = UserConfig::get('timeago');
$data['ddcombine'] = UserConfig::get('ddcombine');
$data['ddmonthyear'] = UserConfig::get('ddmonthyear');
$data['useSummaryAccordion'] = UserConfig::get('useSummaryAccordion', true);
$data['sessions'] = User::getSessions($userID);
$data['history'] = User::getPaymentHistory($userID);
$data['log'] = ZLog::get($userID);

$apiChars = Api::getCharacters($userID);
$domainChars = array();
if ($apiChars != null) {
    foreach ($apiChars as $apiChar) {
        $char = Info::getInfoDetails('characterID', $apiChar['characterID']);
        $char['corpTicker'] = modifyTicker($mdb->findField('information', 'ticker', ['type' => 'corporationID', 'id' => (int) @$char['corporationID']]));
        $char['alliTicker'] = modifyTicker($mdb->findField('information', 'ticker', ['type' => 'corporationID', 'id' => (int) @$char['allianceID']]));

        $domainChars[] = $char;
    }
}

$data['showDisqus'] = UserConfig::get('showDisqus', true);

$app->render('account.html', array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid));

function modifyTicker($ticker)
{
    $ticker = str_replace(' ', '_', $ticker);
    $ticker = preg_replace('/^\./', 'dot.', $ticker);
    $ticker = preg_replace('/\.$/', '.dot', $ticker);

    return strtolower($ticker);
}
