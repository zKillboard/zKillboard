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

$sponsoredShips = [];
$sponsoredTotalIsk = 0;
$expiredThreshold = time() - (86400 * 7);
$sponsoredRows = $mdb->find("sponsored", ['characterID' => (int) $userID], ['entryTime' => -1]);
foreach ($sponsoredRows as $row) {
	$shipTypeID = (int) @$row['victim']['shipTypeID'];
	if ($shipTypeID <= 0) continue;
	$victimName = '';
	if (isset($row['victim']['characterID'])) {
		$victimName = Info::getInfoField('characterID', (int) $row['victim']['characterID'], 'name');
	} else if (isset($row['victim']['allianceID'])) {
		$victimName = Info::getInfoField('allianceID', (int) $row['victim']['allianceID'], 'name');
	} else if (isset($row['victim']['corporationID'])	) {
		$victimName = Info::getInfoField('corporationID', (int) $row['victim']['corporationID'], 'name');
	}
	$victimName = str_ends_with($victimName, 's') ? $victimName . "'" : $victimName . "'s";
	$entryTime = @$row['entryTime'];
	$entryDttm = null;
	$entryTimestamp = null;
	if ($entryTime instanceof MongoDB\BSON\UTCDateTime) {
		$entryDateTime = $entryTime->toDateTime();
		$entryTimestamp = $entryDateTime->getTimestamp();
		$entryDttm = $entryDateTime->format('Y-m-d H:i:s');
	} else if (is_array($entryTime) && isset($entryTime['sec'])) {
		$entryTimestamp = (int) $entryTime['sec'];
		$entryDttm = date('Y-m-d H:i:s', $entryTimestamp);
	}
	$isExpired = ($entryTimestamp !== null && $entryTimestamp < $expiredThreshold) ? 'Yes' : 'No';
	$isk = (int) @$row['isk'];
	$sponsoredTotalIsk += abs($isk);
	$sponsoredShips[] = [
		'shipTypeID' => $shipTypeID,
		'shipName' => Info::getInfoField('typeID', $shipTypeID, 'name'),
		'victimName' => ($victimName ?: 'Unknown Victim'),
		'isk' => @$row['isk'],
		'killID' => (int) @$row['killID'],
		'entryDttm' => $entryDttm,
		'expired' => $isExpired
	];
}
$data['sponsoredShips'] = $sponsoredShips;
$data['sponsoredTotalIsk'] = $sponsoredTotalIsk;

    $accountData = array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid);
    return $container->get('view')->render($response, 'account.html', $accountData);
}
