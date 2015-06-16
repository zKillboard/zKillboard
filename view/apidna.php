<?php

//set the headers to cache the request properly
$dna = array();
$parameters = Util::convertUriToParameters();

$page = 1;
if(isset($parameters["page"]))
	$page = $parameters["page"];

$kills = Feed::getKills(array("limit" => 200, "cacheTime" => 3600, "page" => $page));
foreach($kills as $kill)
{
	$kill = json_decode($kill, true);
	$killdata = Kills::getKillDetails($kill["killID"]);
	$dna[][] = array(
		"killtime" => $killdata["info"]["dttm"], 
		"SolarSystemName" => $killdata["info"]["solarSystemName"],
		"solarSystemID" => $killdata["info"]["solarSystemID"],
		"regionID" => $killdata["info"]["regionID"],
		"regionName" => $killdata["info"]["regionName"],
		"victimCharacterID" => (isset($killdata["victim"]["characterID"]) ? isset($killdata["victim"]["characterID"]) : null),
		"victimCharacterName" => (isset($killdata["victim"]["characterName"]) ? isset($killdata["victim"]["characterName"]) : null),
		"victimCorporationID" => (isset($killdata["victim"]["corporationID"]) ? isset($killdata["victim"]["corporationID"]) : null),
		"victimCorporationName" => (isset($killdata["victim"]["corporationName"]) ? isset($killdata["victim"]["corporationName"]) : null),
		"victimAllianceID" => (isset($killdata["victim"]["allianceID"]) ? isset($killdata["victim"]["allianceID"]) : null),
		"victimAllianceName" => (isset($killdata["victim"]["allianceName"]) ? isset($killdata["victim"]["allianceName"]) : null),
		"victimFactionID" => (isset($killdata["victim"]["factionID"]) ? isset($killdata["victim"]["factionID"]) : null),
		"victimFactionName" => (isset($killdata["victim"]["factionName"]) ? isset($killdata["victim"]["factionName"]) : null),
		"dna" => Fitting::DNA($killdata["items"],$killdata["victim"]["shipTypeID"]));
}
$app->etag(md5(serialize($dna)));
$app->expires("+1 hour");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
$app->contentType("application/json; charset=utf-8");

echo json_encode($dna, JSON_NUMERIC_CHECK);
