<?php

require_once "../init.php";

$counter = 0;
$information = $mdb->getCollection("information");
$timer = new Timer();

$information->update(['type' => 'corporationID', 'lastApiUpdate' => null], ['$set' => ['lastApiUpdate' => new MongoDate(2)]], ['multiple' => true]);

$result = $mdb->find("information", ['type' => 'corporationID', 'lastApiUpdate' => [ '$lt' => $mdb->now(86400)]], ['lastApiUpdate' => 1], 1000);
foreach ($result as $row)
{
	if (Util::exitNow() || $timer->stop() > 110000) exit();

	$updates = [];
	if (!isset($row["memberCount"]) || (isset($row["memberCount"]) && $row["memberCount"] != 0))
	{
		$id = $row["id"];
		sleep(1); // slow things down
		$raw = @file_get_contents("https://api.eveonline.com/corp/CorporationSheet.xml.aspx?corporationID=$id");
		if ($raw != "")
		{
			$counter++;
			$xml = @simplexml_load_string($raw);
			if ($xml != null)
			{
				$corpInfo = $xml->result;
				if (isset($corpInfo->ticker))
				{
					$ceoID = (int) $corpInfo->ceoID;
					$ceoName = (string) $corpInfo->ceoName;
					$updates["ticker"] = (string) $corpInfo->ticker;
					$updates["ceoID"] = $ceoID;
					$updates["memberCount"] = (int) $corpInfo->memberCount;
					$updates["allianceID"] = (int) $corpInfo->allianceID;
					if (!isset($row["name"])) $updates["name"] = (string) $corpInfo->corporationName;

					// Does the CEO exist in our info table?
					$ceoExists = $mdb->count("information", ['type' => 'characterID', 'id' => $ceoID]);
					if ($ceoExists == 0)
					{
						$mdb->insertUpdate("information", ['type' => 'characterID', 'id' => $ceoID], ['name' => $ceoName, 'corporationID' => $id]);
					}
				}
			}
		}
	}
	$updates["lastApiUpdate"] = new MongoDate(time());
	$mdb->insertUpdate("information", ['type' => 'corporationID', 'id' => (int) $row["id"]], $updates);
}
