<?php

require_once "../init.php";

$mdb = new Mdb();
$old = $mdb->now(3600 * 3); // 8 hours
$timer = new Timer();

$mdb->getCollection("information")->update(['type' => 'allianceID', 'lastApiUpdate' => null], ['$set' => ['lastApiUpdate' => new MongoDate(2) ]], ['multiple' => true]);
$alliances = $mdb->find("information", ['type' => 'allianceID', 'lastApiUpdate' => [ '$lt' => $old]], ['lastApiUpdate' => 1], 100);
foreach ($alliances as $alliance)
{
	if (Util::exitNow() || $timer->stop() > 110000) exit();
	$id = $alliance["id"];
	$name = $alliance["name"];
	//echo "$id $name\n";

	$currentInfo = $mdb->findDoc("information", ['type' => 'alliance', 'id' => $id]);

	if (false && @$currentInfo["deleted"] == true)
	{
		$mdb->set("information", ['type' => 'alliance', 'id' => $id], ['lastApiUpdate' => $mdb->now()]);
		continue;
	}

	$alliCrest = CrestTools::getJSON("http://public-crest.eveonline.com/alliances/$id/");
	if ($alliCrest == null || !isset($alliCrest["name"]))
	{
		sleep(1);
		$mdb->set("information", ['type' => 'alliance', 'id' => $id], ['lastApiUpdate' => $mdb->now()]);
		continue;
	}

	$update = [];
	$update["lastApiUpdate"] = $mdb->now();
	$update["corpCount"] = (int) $alliCrest["corporationsCount"];
	$update["executorCorpID"] = (int) $alliCrest["executorCorporation"]["id"];
	addCorp($update["executorCorpID"]);
	$memberCount = 0;
	$update["deleted"] = $alliCrest["deleted"];

	$mdb->set("information", ['type' => 'corporationID', 'allianceID' => $id], ['allianceID' => 0]);
	if ($alliCrest["corporations"]) foreach ($alliCrest["corporations"] as $corp)
	{
		$corpID = (int) $corp["id"];
		addCorp($corpID);
		$infoCorp = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => $corpID]);
		$memberCount += ((int) @$infoCorp["memberCount"]);
		$mdb->set("information", ['type' => 'corporationID', 'id' => $corpID], ['allianceID' => $id]);
	}
	$update["memberCount"] = $memberCount;
	$update["ticker"] = $alliCrest["shortName"];
	$update["name"] = $alliCrest["name"];

	$mdb->insertUpdate("information", ['type' => 'allianceID', 'id' => $id], $update);
	sleep(1);
}

function addCorp($id)
{
	global $mdb;

	$query = ['type' => 'corporationID', 'id' => (int) $id];
	$infoCorp = $mdb->findDoc("information", $query);
	if ($infoCorp == null) $mdb->insertUpdate("information", $query);
}
