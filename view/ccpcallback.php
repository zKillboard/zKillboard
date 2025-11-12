<?php

use cvweiss\redistools\RedisTimeQueue;

function handler($request, $response, $args, $container)
{
	global $esiServer;

	global $mdb, $redis, $adminCharacter, $ip;

	$sessID = session_id();

	if (sizeof($_SESSION) == 0) {
		if ($redis->get("invalid_login:$ip") >= 3) {
			$redis->del("invalid_login:$ip");
			return $container->get('view')->render($response, 'error.html', ['message' => "OK, that's enough.  There is some sort of session bug between you and zkillboard.  Are you trying to run incognito? Are you running adblockers or some plugin for your browser that could be interfering? Let's figure this out and come visit us on Discord."]);
		}
		$redis->incr("invalid_login:$ip", 1);
		$redis->expire("invalid_login:$ip", 120);
		return $response->withStatus(302)->withHeader('Location', '/ccpoauth2/');
	}

	if ($redis->get('zkb:noapi') == 'true') {
		return $container->get('view')->render($response, 'error.html', ['message' => 'Downtime is not a good time to login, the CCP servers are not reliable, sorry.']);
	}

	$sem = sem_get(3174);
	try {
		// Using the semaphore helps prevent this code from simultaneously handling multiple
		// logins from the same person because they double/triple clicked the authorize
		// button on CCP's SSO login page.
		sem_acquire($sem);

		// this should help handle double/triple clicks from ccp's authorization page
		if (@$_SESSION['characterID'] > 0) {
			return $response->withStatus(302)->withHeader('Location', '/');
		}

		$scopeCount = 0;
		$userInfo = null;

		try {
			$sso = ZKillSSO::getSSO();
			$code = filter_input(INPUT_GET, 'code');
			$state = filter_input(INPUT_GET, 'state');
			$userInfo = $sso->handleCallback($code, $state, $_SESSION);
		} catch (Exception $e) {
			Util::zout("SSO Issue\n" . print_r($e, true));
			return $container->get('view')->render($response, 'error.html', ['message' => 'CCP SSO is currently having issues, please wait a moment and try again...']);
		}

		if ($userInfo == null || sizeof($userInfo) == 0) {
			return $container->get('view')->render($response, 'error.html', ['message' => 'CCP SSO is failed to send us the user information, please try logging in again.']);
		}

		$charID = (int) $userInfo['characterID'];
		$charName = $userInfo['characterName'];
		$scopes = explode(' ', $userInfo['scopes']);
		$refresh_token = $userInfo['refreshToken'];
		$access_token = $userInfo['accessToken'];

		// Get latest char details from ESI
		try {
			$sso = EveOnlineSSO::getSSO(['publicData']);
			$charAffiliationRaw = $sso->doCall("$esiServer/latest/characters/affiliation/", [$charID], $access_token, 'POST_JSON');
			$charAffiliation = json_decode($charAffiliationRaw, true);

			if (!is_array($charAffiliation) || empty($charAffiliation) || !isset($charAffiliation[0]['corporation_id'])) {
				throw new Exception('Invalid affiliation data from ESI');
			}

			// Update character affiliation information
			$information = $mdb->getCollection('information');
			$information->updateOne(
				['type' => 'characterID', 'id' => $charID],
				['$set' => [
					'corporationID' => $charAffiliation[0]['corporation_id'],
					'corporation_id' => $charAffiliation[0]['corporation_id'],
					'allianceID' => isset($charAffiliation[0]['alliance_id']) ? $charAffiliation[0]['alliance_id'] : 0,
					'alliance_id' => isset($charAffiliation[0]['alliance_id']) ? $charAffiliation[0]['alliance_id'] : 0,
					'factionID' => isset($charAffiliation[0]['faction_id']) ? $charAffiliation[0]['faction_id'] : 0,
					'faction_id' => isset($charAffiliation[0]['faction_id']) ? $charAffiliation[0]['faction_id'] : 0,
					'lastAffUpdate' => $mdb->now()
				],
					'$setOnInsert' => [
						'type' => 'characterID',
						'id' => $charID,
						'name' => $charName,
						'lastApiUpdate' => 0
					]],
				['upsert' => true]
			);
			$information->updateOne(
				['type' => 'corporationID', 'id' => $charAffiliation[0]['corporation_id']],
				['$setOnInsert' => [
					'name' => 'Corporation ' . $charAffiliation[0]['corporation_id'],
					'type' => 'corporationID',
					'id' => $charAffiliation[0]['corporation_id'],
					'lastApiUpdate' => 0
				]],
				['upsert' => true]
			);
			if (isset($charAffiliation[0]['alliance_id'])) {
				$information->updateOne(
					['type' => 'allianceID', 'id' => $charAffiliation[0]['alliance_id']],
					['$setOnInsert' => [
						'type' => 'allianceID',
						'name' => 'Alliance ' . $charAffiliation[0]['alliance_id'],
						'lastApiUpdate' => 0,
						'id' => $charAffiliation[0]['alliance_id']
					]],
					['upsert' => true]
				);
			}
			$corpID = $charAffiliation[0]['corporation_id'];
			$alliID = isset($charAffiliation[0]['alliance_id']) ? $charAffiliation[0]['alliance_id'] : 0;
		} catch (Exception $e) {
			Util::zout("ESI Affiliation failed for charID $charID: " . $e->getMessage());
			// Fallback: get corp/alliance from existing DB record or set to defaults
			$corpID = Info::getInfoField('characterID', $charID, 'corporationID') ?: 1000001;
			$alliID = Info::getInfoField('characterID', $charID, 'allianceID') ?: 0;
		}

		$redis->setex("recentKillmailActivity:char:$charID", 300, 'true');
		$redis->setex("recentKillmailActivity:corp:$corpID", 300, 'true');

		// Clear out existing scopes
		if ($charID != $adminCharacter)
			$mdb->remove('scopes', ['characterID' => $charID]);
		$delay = (int) $redis->get("delay:$sessID");

		foreach ($scopes as $scope) {
			if ($scope == 'publicData')
				continue;
			$row = ['characterID' => $charID, 'scope' => $scope, 'delay' => $delay, 'refreshToken' => $refresh_token, 'oauth2' => true];
			if ($mdb->count('scopes', ['characterID' => $charID, 'scope' => $scope]) == 0) {
				$mdb->save('scopes', $row);
				$scopeCount++;
			}
			switch ($scope) {
				case 'esi-killmails.read_killmails.v1':
					$esi = new RedisTimeQueue('tqApiESI', 3600);
					$esi->remove($charID);
					$esi->add($charID);
					break;
				case 'esi-killmails.read_corporation_killmails.v1':
					$esi = new RedisTimeQueue('tqCorpApiESI', 3600);
					$esi->remove($corpID);
					if ($corpID > 1999999)
						$esi->add($corpID);
					break;
			}
		}

		// Ensure we have admin character scopes saved, if not, redirect to retrieve them
		if ($charID == $adminCharacter) {
			$neededScopes = ['esi-wallet.read_character_wallet.v1', 'esi-wallet.read_corporation_wallets.v1', 'esi-mail.send_mail.v1'];
			$doRedirect = false;
			foreach ($neededScopes as $neededScope) {
				if ($mdb->count('scopes', ['characterID' => $charID, 'scope' => $neededScope]) == 0)
					$doRedirect = true;
			}
			if ($doRedirect) {
				$sso = ZKillSSO::getSSO($neededScopes);
				return $response->withStatus(302)->withHeader('Location', $sso->getLoginURL($_SESSION));
			}
		}

		if ($scopeCount == 0)
			ZLog::add("Logged in: $charName ($charID) omitted scopes. (Delay: $delay)", $charID, true);
		else
			ZLog::add("Logged in: $charName ($charID) (Delay: $delay)", $charID, true);
		unset($_SESSION['oauth2State']);

		$key = "login:$charID:" . session_id();
		$redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
		$redis->setex("$key:accessToken", 1000, $access_token);
		$redis->setex("$key:scopes", (86400 * 14), @$userInfo['scopes']);

		$_SESSION['characterID'] = $charID;
		$_SESSION['characterName'] = $charName;

		// Determine where to redirect the user
		$redirect = '/';
		$forward = $redis->get("forward:$sessID");
		$redis->del("forward:$sessID");
		$loginPage = UserConfig::get('loginPage', 'character');
		if ($forward !== null) {
			$redirect = $forward;
		} else {
			if ($loginPage == 'main')
				$redirect = '/';
			elseif ($loginPage == 'character')
				$redirect = "/character/$charID/";
			elseif ($loginPage == 'corporation' && $corpID > 0)
				$redirect = "/corporation/$corpID/";
			elseif ($loginPage == 'alliance' && $alliID > 0)
				$redirect = "/alliance/$alliID/";
			else
				$redirect = '/';
		}
		session_write_close();

		if (@$_SESSION['patreon'] == true)
			$redirect = '/cache/bypass/login/patreon/';
		if ($redirect == '')
			$redirect = '/';

		$redis->sadd('queueStatsSet', "characterID:$charID");  // encourage stats calc on newly logged in chars
		return $response->withStatus(302)->withHeader('Location', $redirect);
	} catch (Exception $e) {
		$sessid = session_id();
		Util::zout("$ip $sessid Failed login attempt: " . $e->getMessage() . "\n" . print_r($_SESSION, true));
		if ($e->getMessage() == 'Invalid state returned - possible hijacking attempt') {
			if ($_SESSION['characterID'] > 0) {
				return $response->withStatus(302)->withHeader('Location', '/');
			} else {
				return $container->get('view')->render($response->withStatus(503), 'error.html', ['message' => "Please try logging in again, but don't double/triple click this time. CCP's login form isn't very good at handling multiple clicks... "]);
			}
		} elseif ($e->getMessage() == 'Undefined array key "access_token"') {
			return $container->get('view')->render($response->withStatus(503), 'error.html', ['message' => 'CCP failed to send access token data, please try logging in again.']);
		} else {
			Util::zout(print_r($e, true));
			return $container->get('view')->render($response->withStatus(503), 'error.html', ['message' => $e->getMessage()]);
		}
	} finally {
		sem_release($sem);
	}
}
