<?php

function trackerDashboardData($userID)
{
	global $mdb;

	$globals = (new UserGlobals())->getGlobals();
	$tracked = trackerGroupsFromGlobals($globals);
	$query = trackerDashboardQuery($tracked);
	$killmailLimit = 100;
	$stats = [
		'destroyed' => ['count' => 0, 'isk' => 0, 'points' => 0],
		'lost' => ['count' => 0, 'isk' => 0, 'points' => 0],
	];
	$kills = [];

	if (!empty($query)) {
		$options = [
			'projection' => ['_id' => 0, 'killID' => 1],
			'sort' => ['killID' => -1],
			'limit' => $killmailLimit,
			'maxTimeMS' => 30000,
		];
		$killIDs = iterator_to_array($mdb->getCollection('oneWeek')->find($query, $options));
		$kills = Kills::getDetails($killIDs, true);

		$participantQuery = trackerParticipantQuery($tracked);
		if (!empty($participantQuery)) {
			$stats['destroyed'] = trackerDashboardSummary($participantQuery['kills']);
			$stats['lost'] = trackerDashboardSummary($participantQuery['losses']);
		}
	}

	return ['trackerDashboard' => ['tracked' => $tracked, 'stats' => $stats, 'kills' => $kills, 'killmailLimit' => $killmailLimit]];
}

function trackerGroupsFromGlobals($globals)
{
	$groups = [];
	foreach (['character', 'corporation', 'alliance', 'faction', 'ship', 'group', 'system', 'constellation', 'region'] as $type) {
		$rows = array_map(fn($row) => is_object($row) ? (array) $row : $row, $globals['tracker_' . $type] ?? []);
		$groups[$type] = array_values(array_filter(array_map(fn($row) => (int) ($row['id'] ?? 0), $rows)));
	}
	return $groups;
}

function trackerDashboardQuery($tracked)
{
	$or = trackerParticipantClauses($tracked);
	foreach (['system' => 'solarSystemID', 'constellation' => 'constellationID', 'region' => 'regionID'] as $type => $field) {
		if (!empty($tracked[$type])) $or[] = ['system.' . $field => ['$in' => $tracked[$type]]];
	}
	return empty($or) ? [] : ['$or' => $or];
}

function trackerParticipantQuery($tracked)
{
	$killOr = trackerParticipantClauses($tracked, false);
	$lossOr = trackerParticipantClauses($tracked, true);
	return empty($killOr) ? [] : ['kills' => ['$or' => $killOr], 'losses' => ['$or' => $lossOr]];
}

function trackerParticipantClauses($tracked, $isVictim = null)
{
	$fieldMap = [
		'character' => 'characterID',
		'corporation' => 'corporationID',
		'alliance' => 'allianceID',
		'faction' => 'factionID',
		'ship' => 'shipTypeID',
		'group' => 'groupID',
	];
	$or = [];
	foreach ($fieldMap as $type => $field) {
		if (empty($tracked[$type])) continue;
		$elem = [$field => ['$in' => $tracked[$type]]];
		if ($isVictim !== null) $elem['isVictim'] = $isVictim;
		$or[] = ['involved' => ['$elemMatch' => $elem]];
	}
	return $or;
}

function trackerDashboardSummary($query)
{
	global $mdb;

	$pipeline = [
		['$match' => $query],
		['$group' => ['_id' => null, 'count' => ['$sum' => 1], 'isk' => ['$sum' => '$zkb.totalValue'], 'points' => ['$sum' => '$zkb.points']]],
	];
	$row = current(iterator_to_array($mdb->getCollection('oneWeek')->aggregate($pipeline, ['maxTimeMS' => 30000])));
	return [
		'count' => (int) ($row['count'] ?? 0),
		'isk' => (double) ($row['isk'] ?? 0),
		'points' => (int) ($row['points'] ?? 0),
	];
}

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $templates, $adFreeMonthCost, $baseAddr;
    
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

	$shinyPortraits = Util::getPost('shinyPortraits');
	if (isset($shinyPortraits)) {
		UserConfig::set('shinyPortraits', $shinyPortraits);
		if ($shinyPortraits == 'false') {
			$mdb->removeField("information", ['type' => 'characterID', 'id' => $userID], 'monocle');
			$mdb->removeField("information", ['type' => 'characterID', 'id' => $userID], 'supermonocle');
		} else {
			$userInfo = $mdb->findDoc("users", ['userID' => "user:$userID"]);
			$values = [];
			if (@$userInfo['monocle'] == true) $values['monocle'] = true;
			if (@$userInfo['supermonocle'] == true) $values['supermonocle'] = true;
			if (!empty($values)) $mdb->set("information", ['type' => 'characterID', 'id' => $userID], $values);
		}
		$redis->del(Info::getRedisKey('characterID', $userID));
		$redis->del("RC:" . md5("info:details:characterID:$userID"));
		$redis->del("zkb:overview:character:$userID");
		$redis->del("zkb:overview:characterID:$userID");
		$redis->sadd("queueCacheTags", "overview:$userID");
		User::sendMessage("Your shiny portrait setting was " . ($shinyPortraits == 'false' ? 'disabled' : 'enabled'));
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
$data['shinyPortraits'] = UserConfig::get('shinyPortraits', 'true');
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
	$ship = ['shipTypeID' => $shipTypeID];
	Info::addInfo($ship);
	$sponsoredShips[] = [
		'shipTypeID' => $shipTypeID,
		'shipName' => $ship['shipName'] ?? Info::getInfoField('typeID', $shipTypeID, 'name'),
		'pip' => $ship['pip'] ?? '',
		'victimName' => ($victimName ?: 'Unknown Victim'),
		'isk' => @$row['isk'],
		'killID' => (int) @$row['killID'],
		'entryDttm' => $entryDttm,
		'expired' => $isExpired
	];
}
$data['sponsoredShips'] = $sponsoredShips;
$data['sponsoredTotalIsk'] = $sponsoredTotalIsk;

if ($key == 'tracker') {
	$data = array_merge($data, trackerDashboardData($userID));
}

    $accountData = array('data' => $data, 'message' => $error, 'key' => $key, 'reqid' => $reqid);
    return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,account'), 'account.pug', $accountData);
}
