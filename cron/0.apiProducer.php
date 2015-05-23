<?php

require_once "../init.php";

$apis = $mdb->getCollection("apis");
$information = $mdb->getCollection("information");

$apis->update(['lastApiUpdate' => null], ['$set' => ['lastApiUpdate' => new MongoDate(2)]], ['multiple' => true]);
$apis->update(['killID' => null], ['$set' => ['killID' => 0]], ['multiple' => true]);
$apis->update(['errorCode' => null], ['$set' => ['errorCode' => 0]], ['multiple' => true]);
$apis->update(['errorCode' => 28], ['$set' => ['errorCode' => 0]], ['multiple' => true]);

$timer = new Timer();
$requestNum = 0;

while ($timer->stop() <= 58000)
{
	$row = $apis->findAndModify(['lastApiUpdate' => [ '$lt' => $mdb->now(-10800) ]], ['$set' => ['errorCode' => 0, 'lastApiUpdate' => new MongoDate(time())]], [], [ 'sort' => ['lastApiUpdate' => 1 ]]);

	if ($row == null)
	{
		sleep(1);
		continue;
	}
	$pid = pcntl_fork();
	if ($pid == -1) exit();
	if ($pid != 0) 
	{
		usleep(50000);
		continue;
	}

	$keyID = $row["keyID"];
	$vCode = $row["vCode"];

	if (!isset($row["characters"])) $row["characters"] = [];
	$hasKills = false;
	foreach ($row["characters"] as $charID=>$killID)
	{
		$hasKills |= $killID > 0;
	}

	$errorCode = (int) @$row["errorCode"];
	if ($errorCode == 0 || $errorCode == 221)
	{
		\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
		\Pheal\Core\Config::getInstance()->http_post = false;
		\Pheal\Core\Config::getInstance()->http_keepalive = true; // default 15 seconds
		\Pheal\Core\Config::getInstance()->http_keepalive = 10; // KeepAliveTimeout in seconds
		\Pheal\Core\Config::getInstance()->http_timeout = 30;
		//if ($phealCacheLocation != null) \Pheal\Core\Config::getInstance()->cache = new \Pheal\Cache\FileStorage($phealCacheLocation);
		\Pheal\Core\Config::getInstance()->api_customkeys = true;

		$requestNum++;
		$apiServer = $apiServers[($requestNum % (sizeof($apiServers)))];
		\Pheal\Core\Config::getInstance()->api_base = $apiServer;
		$pheal = new \Pheal\Pheal($keyID, $vCode);
		try
		{
			$apiKeyInfo = $pheal->ApiKeyInfo();
		} catch (Exception $ex)
		{
			$errorCode = (int) $ex->getCode();
			if ($errorCode == 904) { Util::out("(apiProducer) 904'ed"); exit(); }
			if ($errorCode == 28)
			{
				$apis->update(['_id' => $row["_id"]],  ['$set' => ['lastApiUpdate' => $mdb->now(-9600)]]);
				Util::out("(apiProducer) API Server timeout");
				exit();
			}
			if ($errorCode != 221 && $debug) Util::out("(apiProducer) Error Validating $keyID: " . $ex->getCode() . " " . $ex->getMessage());
			$apis->update(['_id' => $row["_id"]], ['$set' => ['errorCode' => $errorCode]]);
		}    

		if ($errorCode == 0)
		{
			$key = @$apiKeyInfo->key;
			$accessMask = @$key->accessMask;
			$characterIDs = array();
			if ($accessMask & 256)
			{
				foreach ($apiKeyInfo->key->characters as $character)
				{
					$characterID = (int) $character->characterID;
					if (!isset($row["characters"][$characterID])) $row["characters"]["$characterID"] = 0;
					$lastKillID = $row["characters"]["$characterID"];

					// Make sure we have the names and id's in the information table
					$mdb->insertUpdate("information", ['type' => 'corporationID', 'id' => ((int) $character->corporationID)], ['name' => ((string) $character->corporationName)]);
					$mdb->insertUpdate("information", ['type' => 'characterID', 'id' => ((int) $characterID)], ['name' => ((string) $character->characterName), 'corporationID' => ((int) $character->corporationID)]);

					$type = $apiKeyInfo->key->type;
					if ($debug) Util::out("Adding $keyID $characterID $type $vCode");

					if (!$mdb->exists("apiCharacters", ['keyID' => $keyID, 'vCode' => $vCode, 'characterID' => $characterID, 'type' => $type])) $mdb->insert("apiCharacters", ['keyID' => $keyID, 'vCode' => $vCode, 'characterID' => $characterID, 'corporationID' => ((int) $character->corporationID), 'type' => $type, 'cachedUntil' => new MongoDate(2)]);
				}
			}
		}
	}
	exit();
}
