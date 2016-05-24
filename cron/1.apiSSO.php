<?php

require_once "../init.php";


$xmlSuccess = new RedisTtlCounter('ttlc:XmlSuccess', 300);
$xmlFailure = new RedisTtlCounter('ttlc:XmlFailure', 300);
$chars = [];

$timer = new Timer();

while ($timer->stop() < 60000) {
	$t = new Timer();
	$apis = $mdb->find("apisCrest", ['lastFetch' => ['$lt' => (time() - 1800)]], ['lastFetch' => 1], 1);
	$tt = $t->stop();
	if (sizeof($apis) == 0) sleep(1);
	foreach ($apis as $row)
	{
		$charID = $row['characterID'];
		$lastFetch = $row['lastFetch'];
		if (in_array($charID, $chars)) continue;
		$chars[] = $charID;
		$refreshToken = $row['refreshToken'];
		$accessToken = CrestSSO::getAccessToken($charID, "", $refreshToken);
		if (is_array($accessToken))
		{
			$error = $accessToken['error'];
			if ($error == 'invalid_grant')
			{
				$mdb->remove("apisCrest", $row);
			}
			else
			{
				Util::out("SSO xml unhandled error: " . $error . " - " . $accessToken['error_description']);
			}
			continue;
		}
		if ($accessToken == null) { Util::out("null access token on $charID $refreshToken"); continue; }
		$killsAdded = 0;

		\Pheal\Core\Config::getInstance()->http_method = 'curl';
		\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for https://$baseAddr";
		\Pheal\Core\Config::getInstance()->http_post = false;
		\Pheal\Core\Config::getInstance()->http_keepalive = 30; // KeepAliveTimeout in seconds
		\Pheal\Core\Config::getInstance()->http_timeout = 60;
		\Pheal\Core\Config::getInstance()->api_customkeys = true;
		\Pheal\Core\Config::getInstance()->api_base = 'https://api.eveonline.com/';
		$pheal = new \Pheal\Pheal();

		$pheal->scope = 'char';
		$result = null;

		$params = ['characterID' => $charID, 'accessToken' => $accessToken];

		try {
			$result = $pheal->KillMails($params);
			$xmlSuccess->add(uniqid());
			$v = $mdb->set("apisCrest", ['characterID' => $charID], ['lastFetch' => time()], true);
		} catch (Exception $ex) {
			$xmlFailure->add(uniqid());
			$errorCode = $ex->getCode();
			if ($errorCode == 904) {
				Util::out("(apiConsumer) 904'ed...");
				exit();
			}
			if ($errorCode == 28) {
				exit();
			}
			if ($errorCode == 201) {
				$mdb->remove("apisCrest", $row);
				continue;
			}
			Util::out("Unknown error for SSO xml api - $charID - " . $ex->getMessage());
			sleep(3);
			continue;
		}

		$nextCheck = $result->cached_until_unixtime;

		$newMaxKillID = 0;
		foreach ($result->kills as $kill) {
			$killID = (int) $kill->killID;

			$newMaxKillID = (int) max($newMaxKillID, $killID);

			$json = json_encode($kill->toArray());
			$killmail = json_decode($json, true);
			$killmail['killID'] = (int) $killID;

			$victim = $killmail['victim'];
			$victimID = $victim['characterID'] == 0 ? 'None' : $victim['characterID'];

			$attackers = $killmail['attackers'];
			$attacker = null;
			if ($attackers != null) {
				foreach ($attackers as $att) {  
					if ($att['finalBlow'] != 0) {
						$attacker = $att;
					}
				}
			}
			if ($attacker == null) {
				$attacker = $attackers[0];
			}
			$attackerID = $attacker['characterID'] == 0 ? 'None' : $attacker['characterID'];

			$shipTypeID = $victim['shipTypeID'];

			$dttm = (strtotime($killmail['killTime']) * 10000000) + 116444736000000000;

			$string = "$victimID$attackerID$shipTypeID$dttm";

			$hash = sha1($string);

			$killInsert = ['killID' => (int) $killID, 'hash' => $hash];
			$exists = $mdb->exists('crestmails', $killInsert);
			if (!$exists) {
				try {
					$mdb->getCollection('crestmails')->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'sso', 'added' => $mdb->now()]);
					++$killsAdded;
				} catch (MongoDuplicateKeyException $ex) {
					// ignore it *sigh*
				}
			}
			if (!$exists && $debug) {
				Util::out("Added $killID from API");
			}
		}

		// helpful info for output if needed
		$info = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID], [], ['name' => 1, 'corporationID' => 1]);
		$corpInfo = $mdb->findDoc('information', ['type' => 'corporationID', 'id' => @$info['corporationID']], [], ['name' => 1]);

		$apiVerifiedSet = new RedisTtlSortedSet('ttlss:apiVerified', 86400);
		$apiVerifiedSet->add(time(), $charID);

		// If we got new kills tell the log about it
		if ($killsAdded > 0) {
			$name = 'char '.@$info['name'];
			while (strlen("$killsAdded") < 3) {
				$killsAdded = ' '.$killsAdded;
			}
			Util::out("$killsAdded kills added by $name (SSO)");
		}
	}
	sleep(1);
}
