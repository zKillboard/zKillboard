<?php

global $mdb;

if (!User::isLoggedIn()) {
    $app->redirect('/ccpoauth2/', 302);
    die();
}

$userID = (int) User::getUserID();
$key = 'sitesettings';
$error = '';

$bannerUpdates = array();
$aliasUpdates = array();

if (isset($req)) {
    $key = $req;
}

global $twig, $adFreeMonthCost, $baseAddr;
if ($_POST) {
    $deletekeyid = Util::getPost('deletekeyid');
    $deleteentity = Util::getPost('deleteentity');
    // Delete an apikey
    if (isset($deletekeyid)) {
        Log::log("Character $userID deleting scope " . $deletekeyid);
        try {
            $i = $mdb->remove("scopes", ['characterID' => $userID, 'scope' => $deletekeyid]);
            if (isset($i['n']) && $i['n'] > 0) $error = "The scope has been removed.";
            else $error = "We did nothing. Were you supposed to attempt that?";
            User::sendMessage($error);
        } catch (Exception $e) {
            Log::log(print_r($e, true));
            User::sendMessage("An error occurred and has been logged. Sorry.");
        }
    }

    // Style
    $style = Util::getPost('style');
    if (isset($style)) {
        UserConfig::set('style', $style);
        User::sendMessage("Your theme was updated to $style");
    }

    // Disqus
    $showDisqus = Util::getPost('showDisqus');
    if (isset($showDisqus)) {
        UserConfig::set('showDisqus', $showDisqus);
        User::sendMessage("Your Disqus setting was updated to " . ($showDisqus != "true" ? "not show." : "show."));
    }

    $loginPage = Util::getPost('loginPage');
    if (isset($loginPage)) {
        UserConfig::set('loginPage', $loginPage);
        User::sendMessage("Your default login page is now the $loginPage page");
    }

    $app->redirect($_SERVER['REQUEST_URI']);
    exit();
}

// Theme
$theme = UserConfig::get('theme', 'zkillboard');
$data['themesAvailable'] = [];
$data['currentTheme'] = $theme;

// Style
$data['stylesAvailable'] = Util::availableStyles();
$data['currentStyle'] = UserConfig::get('style');


$data['loginPage'] = UserConfig::get('loginPage', 'character');
$data['apiScopes'] = $mdb->find("scopes", ['characterID' => (int) $userID], ['scope' => 1]);
$data['history'] = User::getPaymentHistory($userID);
$data['log'] = ZLog::get($userID);

$app->render('account.html', array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid));
