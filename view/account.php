<?php

global $mdb, $redis;

if (User::checkForLogin($app, "/account/$req/") == false) return;

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
		Util::zout("Character $userID deleting scope " . $deletekeyid);
		try {
			$i = $mdb->remove("scopes", ['characterID' => $userID, 'scope' => $deletekeyid]);
			if (isset($i['n']) && $i['n'] > 0) $error = "The scope has been removed.";
			else $error = "We did nothing. Were you supposed to attempt that?";
			User::sendMessage($error);
		} catch (Exception $e) {
			Util::zout(print_r($e, true));
			User::sendMessage("An error occurred and has been logged. Sorry.");
		}
	}

	// Tracker Notification
	$tn = Util::getPost('trackernotification');
	if (isset($tn)) {
		UserConfig::set('trackernotification', $tn);
		User::sendMessage("Your tracker notification setting was updated to $tn");
	}

	// Style
	$style = Util::getPost('style');
	if (isset($style)) {
		UserConfig::set('style', $style);
		User::sendMessage("Your theme was updated to $style");
	}

	$loginPage = Util::getPost('loginPage');
	if (isset($loginPage)) {
		UserConfig::set('loginPage', $loginPage);
		User::sendMessage("Your default login page is now the $loginPage page");
	}

	return $app->redirect($_SERVER['REQUEST_URI']);
}

// Theme
$theme = UserConfig::get('theme', 'zkillboard');
$data['themesAvailable'] = [];
$data['currentTheme'] = $theme;

// Style
$data['stylesAvailable'] = Util::availableStyles();
$data['currentStyle'] = UserConfig::get('style');
$data['trackernotification'] = UserConfig::get('trackernotification');

$data['loginPage'] = UserConfig::get('loginPage', 'character');
$data['apiScopes'] = $mdb->find("scopes", ['characterID' => (int) $userID], ['scope' => 1]);
$data['history'] = User::getPaymentHistory($userID);
$data['log'] = ZLog::get($userID);

$app->render('account.html', array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid));
