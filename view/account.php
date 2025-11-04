<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $twig, $adFreeMonthCost, $baseAddr;
    
    $req = $args['req'] ?? null;
    $reqid = $args['reqid'] ?? null;

    // Handle login check
    if (!User::isLoggedIn()) {
        $sessID = session_id();
        if ("/account/$req/" != '') {
            $redis->setex("forward:$sessID", 900, "/account/$req/");
        }
        return $response->withStatus(302)->withHeader('Location', '/ccpoauth2/');
    }

    $userID = (int) User::getUserID();
    $key = 'sitesettings';
    $error = '';

    $bannerUpdates = array();
    $aliasUpdates = array();

    if (isset($req)) {
        $key = $req;
    }
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

	return $response->withStatus(302)->withHeader('Location', $request->getUri()->getPath() . '?' . $request->getUri()->getQuery());
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

    $accountData = array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid);
    return $container->view->render($response, 'account.html', $accountData);
}
