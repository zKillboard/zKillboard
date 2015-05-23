<?php

require_once "../init.php";

$counter = 0;
$information = $mdb->getCollection("information");
$timer = new Timer();
$counter = 0;

// This method doesn't lock the database for seconds at a time
$notSet = $information->find(['type' => 'characterID', 'lastApiUpdate' => null]);
foreach ($notSet as $row)
{
	$information->update($row, ['$set' => ['lastApiUpdate' => new MongoDate(2)]]);
}

while ($timer->stop() < 55000)
{
	$result = $mdb->find("information", ['type' => 'characterID', 'lastApiUpdate' => [ '$lt' => $mdb->now(86400)]], ['lastApiUpdate' => 1], 100);
	$ids = [];
	foreach ($result as $row) $ids[] = $row["id"];
	$stringIDs = implode(",", $ids);
	$href = "https://api.eveonline.com/eve/CharacterAffiliation.xml.aspx?ids=$stringIDs";
	$raw = file_get_contents($href);
	$xml = @simplexml_load_string($raw);

	foreach ($xml->result->rowset->row as $info)
	{
		$updates = [];

		$id = (int) $info["characterID"];
		$row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $id]);
		if (isset($info["characterName"]))
		{
$counter++;
			if ($row["name"] != (string) $info["characterName"]) $mdb->set("information", $row, ['name' => (string) $info["characterName"]]);
			if (@$row["corporationID"] != (int) $info["corporationID"]) $updates[] = ['corporationID' => (int) $info["corporationID"]];
			if (!$mdb->exists("information", ['type' => 'corporationID', 'id' => (int) $info["corporationID"]])) $mdb->insert("information", ['type' => 'corporationID', 'id' => (int) $info["corporationID"], 'name' => (string) $info["corporationName"]]);

			if (isset($row["allianceID"]) && $info["allianceID"] == 0) $mdb->removeField("information", $row, "allianceID");
			else if (@$row["allianceID"] != (int) $info["allianceID"]) $updates[] = ['allianceID' => (int) $info["allianceID"]];
			if ($info["allianceID"] != 0 && !$mdb->exists("information", ['type' => 'allianceID', 'id' => (int) $info["allianceID"]])) $mdb->insert("information", ['type' => 'allianceID', 'id' => (int) $info["allianceID"], 'name' => (string) $info["allianceName"]]);

			if (isset($row["factionID"]) && $info["factionID"] == 0) $mdb->removeField("information", $row, "factionID");
			else if (@$row["factionID"] != (int) $info["factionID"]) $updates[] = ['factionID' => (int) $info["factionID"]];
			if ($info["factionID"] != 0 && !$mdb->exists("information", ['type' => 'factionID', 'id' => (int) $info["factionID"]])) $mdb->insert("information", ['type' => 'factionID', 'id' => (int) $info["factionID"], 'name' => (string) $info["factionName"]]);
		}
		$updates["lastApiUpdate"] = new MongoDate(time());
		$mdb->insertUpdate("information", ['type' => 'characterID', 'id' => (int) $row["id"]], $updates);
	}
	sleep(1);
}
