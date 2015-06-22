<?php

for ($i = 0; $i < 20; $i++)
{
	$pid = pcntl_fork();
	if ($pid == -1) exit();
	if ($pid == 0) break;
}

require_once "../init.php";

$apis = $mdb->getCollection("apis");
$information = $mdb->getCollection("information");
$tqApis = new RedisTimeQueue("tqApis", 9600);
$tqApiChars = new RedisTimeQueue("tqApiChars");

if ($pid != 0 && date("i") % 5 == 0)
{
	$apis->update(['killID' => null], ['$set' => ['killID' => 0]], ['multiple' => true]);
	$apis->update(['errorCode' => null], ['$set' => ['errorCode' => 0]], ['multiple' => true]);
	$apis->update(['errorCode' => 28], ['$set' => ['errorCode' => 0]], ['multiple' => true]);

	$allApis = $mdb->find("apis");
	foreach ($allApis as $api)
	{
		$keyID = $api["keyID"];
		$vCode = $api["vCode"];
		$value = ["keyID" => $keyID, "vCode" => $vCode];
		if (@$api["errorCode"] != 0) $tqApis->remove($value);
		else $tqApis->add($value);
	}
}
if ($pid != 0) exit();

$timer = new Timer();
$requestNum = 0;

while ($timer->stop() <= 58000)
{
	$row = $tqApis->next();
	if ($row !== null) 
	{

		$keyID = $row["keyID"];
		$vCode = $row["vCode"];

		if (!isset($row["characters"])) $row["characters"] = [];

		$errorCode = (int) @$row["errorCode"];
		if ($errorCode == 0 || $errorCode == 221)
		{
			\Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
			\Pheal\Core\Config::getInstance()->http_post = false;
			\Pheal\Core\Config::getInstance()->http_keepalive = true; // default 15 seconds
			\Pheal\Core\Config::getInstance()->http_keepalive = 10; // KeepAliveTimeout in seconds
			\Pheal\Core\Config::getInstance()->http_timeout = 30;
			\Pheal\Core\Config::getInstance()->api_customkeys = true;

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
					Util::out("(apiProducer) API Server timeout");
					exit();
				}
				$tqApis->remove($row); // Problem with api the key, remove it from rotation
				if ($errorCode != 221 && $debug) Util::out("(apiProducer) Error Validating $keyID: " . $ex->getCode() . " " . $ex->getMessage());
				$apis->update(['keyID' => $keyID, 'vCode' => $vCode], ['$set' => ['errorCode' => $errorCode]]);
				continue;
			}    

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

					$char = ['keyID' => $keyID, 'vCode' => $vCode, 'characterID' => $characterID, 'type' => $type];
					$tqApiChars->add($char);
				}
			}
		}
	}
	sleep(1);
}
