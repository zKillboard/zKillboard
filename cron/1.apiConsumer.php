<?php

require_once "../init.php";

$timer = new Timer();

while ($timer->stop() <= 58000)
{
	$rows = $mdb->find("apiCharacters", ['cachedUntil' => ['$lt' => $mdb->now()]], ['cachedUntil' => 1], 1000);
	if ($rows == null || sizeof($rows == 0)) sleep(1);
	foreach ($rows as $row)
	{
		if ($timer->stop() > 58000) exit();
		$mdb->set("apiCharacters", $row, ['cachedUntil' => $mdb->now(7200)]);

		$pid = pcntl_fork();
		if ($pid == -1) exit();
		if ($pid != 0)
		{
			usleep(50000);
			continue;
		}

		$charID = $row["characterID"];
		$keyID = $row["keyID"];
		$vCode = $row["vCode"];
		$type = $row["type"];
		$maxKillID = (int) @$row["killID"];
		$charCorp = $type == "Corporation" ? "corp" : "char";
		$killsAdded = 0;

		\Pheal\Core\Config::getInstance()->http_method = "curl";
		\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
		\Pheal\Core\Config::getInstance()->http_post = false;
		\Pheal\Core\Config::getInstance()->http_keepalive = 30; // KeepAliveTimeout in seconds
		\Pheal\Core\Config::getInstance()->http_timeout = 60;
		//if ($phealCacheLocation != null) \Pheal\Core\Config::getInstance()->cache = new \Pheal\Cache\FileStorage($phealCacheLocation);
		\Pheal\Core\Config::getInstance()->api_customkeys = true;
		\Pheal\Core\Config::getInstance()->api_base = "https://api.eveonline.com/";
		$pheal = new \Pheal\Pheal($keyID, $vCode);

		$charCorp = ($type == "Corporation" ? 'corp' : 'char');
		$pheal->scope = $charCorp;
		$result = null;

		$params = array();
		$params['characterID'] = $charID;
		$result = null;

		try 
		{
			$result = $pheal->KillMails($params);
			//Util::out("(apiConsumer) Poked KillLog $keyID $vCode $charID");
		} catch (Exception $ex)
		{
			$errorCode = $ex->getCode();
			if ($errorCode == 904) { Util::out("(apiConsumer) 904'ed..."); exit(); }
			if ($errorCode == 28)
			{
				Util::out("(apiConsumer) API Server timeout");
				exit();
			}
			// Error code 0: Scotty is up to his shenanigans again (aka server issue)
			// Error code 221: server randomly throwing an illegal access error even though this is a legit call
			if ($errorCode != 0 && $errorCode != 221) $mdb->remove("apiCharacters", $row);
			exit();
		}
		$newMaxKillID = $maxKillID;
		foreach ($result->kills as $kill)
		{
			$killID = (int) $kill->killID;

			//$exists = $mdb->exists("killmails", ['killID' => $killID]);
			$newMaxKillID = (int) max($newMaxKillID, $killID);

			$json = json_encode($kill->toArray());
			$killmail = json_decode($json, true);
			$killmail["killID"] = (int) $killID; // make sure killID is an int;
			if (!$mdb->exists("crestmails", ['killID' => $killID]) && !$mdb->exists("apimails", ['killID' => $killID])) $mdb->insertUpdate("apimails", $killmail);

			$victim = $killmail["victim"];
			$victimID = $victim["characterID"] == 0 ? "None" : $victim["characterID"];

			$attackers = $killmail["attackers"];
			$attacker = null;
			if ($attackers != null) foreach($attackers as $att)
			{
				if ($att["finalBlow"] != 0) $attacker = $att;
			}
			if ($attacker == null) $attacker = $attackers[0];
			$attackerID = $attacker["characterID"] == 0 ? "None" : $attacker["characterID"];

			$shipTypeID = $victim["shipTypeID"];

			$dttm = (strtotime($killmail["killTime"]) * 10000000) + 116444736000000000;

			$string = "$victimID$attackerID$shipTypeID$dttm";

			$hash = sha1($string);

			$killInsert = ['killID' => (int) $killID, 'hash' => $hash];
			$exists = $mdb->exists("crestmails", $killInsert);
			if (!$exists) $mdb->getCollection("crestmails")->save(['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'api', 'added' => $mdb->now()]);
			if (!$exists) $killsAdded++;
			if (!$exists && $debug) Util::out("Added $killID from API");
		}

		// helpful info for output if needed
		$info = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $charID], [], [ 'name' => 1, 'corporationID' => 1]);
		$corpInfo = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => @$info["corporationID"]], [], [ 'name' => 1]);

		// If we got new kills tell the log about it
		if ($killsAdded > 0)
		{
			if ($type == "Corporation") $name = "corp " . @$corpInfo["name"];
			else $name = "char " . @$info["name"];
			while (strlen("$killsAdded") < 3) $killsAdded = " " . $killsAdded;
			Util::out("$killsAdded kills added by $name");
		}

		// Temp code to show chars as api verified in the mariadb
		$corpID = (int) @$info["corporationID"];
		//Db::execute("replace into zz_api_characters (keyID, characterID, corporationID, isDirector, maxKillID, lastChecked, errorCode) values (:keyID, :charID, :corpID, :isD, :maxKillID, now(), 0)", array(":keyID" => $keyID, ":charID" => $charID, ":isD" => ($type == "Corporation" ? 'T' : 'F'), ":maxKillID" => $newMaxKillID, ":corpID" => $corpID));

		$cachedUntil = $newMaxKillID == 0 ? $mdb->now(86400) : new MongoDate(strtotime($result->cached_until));
		$mdb->set("apiCharacters", $row, ['maxKillID' => $newMaxKillID, 'cachedUntil' => $cachedUntil]);
		exit();
	}
}
