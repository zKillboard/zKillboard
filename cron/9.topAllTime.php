<?php

require_once "../init.php";

if (date("Hi") != "1100") exit();

$types = ['allianceID', 'corporationID', 'factionID'];

foreach ($types as $type)
{
	$entities = $mdb->find("statistics", ['type' => $type]);
	foreach ($entities as $row) calcTop($row);
}

function calcTop($row)
{
	global $mdb;

	if (date("d") != "01" && isset($row["topAllTime"])) return;

	$parameters = [$row["type"] => $row["id"]];
        $parameters["limit"] = 10;
        $parameters["kills"] = true;

        $topLists[] = array("type" => "character", "data" => Stats::getTop("characterID", $parameters));
        $topLists[] = array("type" => "corporation", "data" => Stats::getTop("corporationID", $parameters, true));
        $topLists[] = array("type" => "alliance", "data" => Stats::getTop("allianceID", $parameters, true));
        $topLists[] = array("type" => "faction", "data" => Stats::getTop("factionID", $parameters, true));
        $topLists[] = array("type" => "ship", "data" => Stats::getTop("shipTypeID", $parameters, true));
        $topLists[] = array("type" => "system", "data" => Stats::getTop("solarSystemID", $parameters, true));
	do {
		$r = $mdb->set("statistics", $row, ['topAllTime' => $topLists]);
	} while ($r["ok"] != 1);
}
