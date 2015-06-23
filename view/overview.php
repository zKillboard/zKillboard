<?php

global $mdb, $redis;

$key = $input[0];
if (!isset($input[1])) $app->redirect("/");
$id = $input[1];
$pageType = @$input[2];

if (strlen("$id") > 11) $app->redirect("/");

if ($pageType == "history") $app->redirect("../stats/");

$validPageTypes = array("overview", "kills", "losses", "solo", "stats", "wars", "supers", "top");
if ($key == "alliance")
{
	$validPageTypes[] = "api";
	$validPageTypes[] = "corpstats";
}
$validPageTypes[] = "top";
$validPageTypes[] = "topalltime";

if (!in_array($pageType, $validPageTypes)) $pageType = "overview";

$map = array(
		"corporation"   => array("column" => "corporation", "id" => "Info::getCorpId", "details" => "Info::getCorpDetails", "mixed" => true),
		"character"     => array("column" => "character", "id" => "Info::getCharId", "details" => "Info::getPilotDetails", "mixed" => true),
		"alliance"      => array("column" => "alliance", "id" => "Info::getAlliId", "details" => "Info::getAlliDetails", "mixed" => true),
		"faction"       => array("column" => "faction", "id" => "Info::getFactionId", "details" => "Info::getFactionDetails", "mixed" => true),
		"system"        => array("column" => "solarSystem", "id" => "Info::getSystemId", "details" => "Info::getSystemDetails", "mixed" => true),
		"region"        => array("column" => "region", "id" => "Info::getRegionId", "details" => "Info::getRegionDetails", "mixed" => true),
		"group"		=> array("column" => "group", "id" => "Info::getGroupIDFromName", "details" => "Info::getGroupDetails", "mixed" => true),
		"ship"          => array("column" => "shipType", "id" => "Info::getShipId", "details" => "Info::getShipDetails", "mixed" => true),
	    );
if (!array_key_exists($key, $map)) $app->notFound();

if (!is_numeric($id))
{
	$app->redirect("./../"); exit();
	$function = $map[$key]["id"];
	$id = call_user_func($function, $id);
	if ($id > 0) $app->redirect("/" . $key . "/" . $id . "/", 302);
	else $app->notFound();
}

if ($id <= 0) $app->notFound();

$parameters = Util::convertUriToParameters();
@$page = max(1, $parameters["page"]);
global $loadGroupShips; // Can't think of another way to do this just yet
$loadGroupShips = $key == "group";

$limit = 50;
$parameters["limit"] = $limit;
$parameters["page"] = $page;
try {
	$detail = call_user_func($map[$key]["details"], $id, $parameters);
	if (isset($detail["valid"]) && $detail["valid"] == false) $app->notFound();
} catch (Exception $ex) 
{
	$app->render("error.html", array("message" => "There was an error fetching information for the $key you specified."));
	return;
}
$pageName = isset($detail[$map[$key]["column"] . "Name"]) ? $detail[$map[$key]["column"] . "Name"] : "???";
if ($pageName == "???" && !$mdb->exists("information", ['id' => $id]))
{
	return $app->render("404.html", array("message" => "This entity is not in our database."), 404);
	die();
}
$columnName = $map[$key]["column"] . "ID";
$mixedKills = $pageType == "overview" && $map[$key]["mixed"] && UserConfig::get("mixKillsWithLosses", true);

$mixed = $pageType == "overview" ? Kills::getKills($parameters) : array();
$kills = $pageType == "kills"    ? Kills::getKills($parameters) : array();
$losses = $pageType == "losses"  ? Kills::getKills($parameters) : array();

if ($pageType != "solo" || $key == "faction") {
	$soloKills = array();
	//$soloCount = 0;
} else {
	$soloParams = $parameters;
	if (!isset($parameters["kills"]) || !isset($parameters["losses"])) $soloParams["mixed"] = true;
	$soloKills = Kills::getKills($soloParams);
}
//$soloPages = ceil($soloCount / $limit);
$solo = Kills::mergeKillArrays($soloKills, array(), $limit, $columnName, $id);

$validAllTimePages = array("character", "corporation", "alliance", "faction");
$topLists = array();
$topKills = array();
if ($pageType == "top" || $pageType == "topalltime") {
	$topParameters = $parameters; // array("limit" => 10, "kills" => true, "$columnName" => $id);
	$topParameters["limit"] = 10;

	if ($pageType == "topalltime" && $key != "character")
	{
		$useType = $key;
		if ($useType == "ship") $useType = "shipType";
		$topLists = $mdb->findField("statistics", "topAllTime", ['type' => "{$useType}ID", 'id' => (int) $id]);
	} else {
		if($pageType != "topalltime")
		{
			if(!isset($topParameters["year"]))
				$topParameters["year"] = date("Y");
			if(!isset($topParameters["month"]))
				$topParameters["month"] = date("m");
		}
		if (!array_key_exists("kills", $topParameters) && !array_key_exists("losses", $topParameters)) $topParameters["kills"] = true;

		$topLists[] = array("type" => "character", "data" => Stats::getTopPilots($topParameters, true));
		$topLists[] = array("type" => "corporation", "data" => Stats::getTopCorps($topParameters, true));
		$topLists[] = array("type" => "alliance", "data" => Stats::getTopAllis($topParameters, true));
		$topLists[] = array("type" => "ship", "data" => Stats::getTopShips($topParameters, true));
		$topLists[] = array("type" => "system", "data" => Stats::getTopSystems($topParameters, true));
		//$topLists[] = array("type" => "weapon", "data" => Stats::getTopWeapons($topParameters, true));

		if (isset($detail["factionID"]) && $detail["factionID"] != 0 && $key != "faction") {
			$topParameters["!factionID"] = 0;
			$topLists[] = array("name" => "Top Faction Characters", "type" => "character", "data" => Stats::getTopPilots($topParameters, true));
			$topLists[] = array("name" => "Top Faction Corporations", "type" => "corporation", "data" => Stats::getTopCorps($topParameters, true));
			$topLists[] = array("name" => "Top Faction Alliances", "type" => "alliance", "data" => Stats::getTopAllis($topParameters, true));
		}
	}
} else {
	$p = $parameters;
	$numDays = 7;
	$p["limit"] = 10;
	$p["pastSeconds"] = $numDays * 86400;
	$p["kills"] = $pageType != "losses";

	if ($key != "character") {
		$topLists[] = Info::doMakeCommon("Top Characters", "characterID", Stats::getTopPilots($p));
		if ($key != "corporation") {
			$topLists[] = Info::doMakeCommon("Top Corporations", "corporationID", Stats::getTopCorps($p));
			if ($key != "alliance") {
				$topLists[] = Info::doMakeCommon("Top Alliances", "allianceID", Stats::getTopAllis($p));
			}
		}
	}
	if ($key != "ship") $topLists[] = Info::doMakeCommon("Top Ships", "shipTypeID", Stats::getTopShips($p));
	if ($key != "system") $topLists[] = Info::doMakeCommon("Top Systems", "solarSystemID", Stats::getTopSystems($p));
	$p["limit"] = 5;
	$topKills = Stats::getTopIsk($p);
}

$corpList = array();
if ($pageType == "api") $corpList = Info::getCorps($id);

$corpStats = array();
if ($pageType == "corpstats") $corpStats = Info::getCorpStats($id, $parameters);

$onlyHistory = array("character", "corporation", "alliance");
if ($pageType == "stats" && in_array($key, $onlyHistory)) {
	$detail["history"] = Summary::getMonthlyHistory($columnName, $id);
} else $detail["history"] = array();

// Figure out if the character or corporation has any API keys in the database
$apiVerified = false;
$nextApiCheck = null;
if(in_array($key, array("character", "corporation")))
{
	$apiVerifiedSet = new RedisTtlSortedSet("ttlss:apiVerified", 86400);
	$apiVerified = $apiVerifiedSet->getTime((int) $id);
	$nextApiCheck = date("H:i", $apiVerified + 3600);
}

$cnt = 0;
$cnid = 0;
$stats = array();
$totalcount = ceil(count($detail["stats"]) / 4);
if ($detail["stats"] != null) foreach($detail["stats"] as $q)
{
	if($cnt == $totalcount)
	{
		$cnid++;
		$cnt = 0;
	}
	$stats[$cnid][] = $q;
	$cnt++;
}
if ($mixedKills) $kills = Kills::mergeKillArrays($mixed, array(), $limit, $columnName, $id);

$prevID = null;
$nextID = null;

$warID = (int) $id;
$extra = array();
$extra["hasWars"] = false; //Db::queryField("select count(distinct warID) count from zz_wars where aggressor = $warID or defender = $warID", "count");
$extra["wars"] = array();
if (false && $pageType == "wars" && $extra["hasWars"])
{
	$extra["wars"][] = War::getNamedWars("Active Wars - Aggressor", "select * from zz_wars where aggressor = $warID and timeFinished is null order by timeStarted desc");
	$extra["wars"][] = War::getNamedWars("Active Wars - Defending", "select * from zz_wars where defender = $warID and timeFinished is null order by timeStarted desc");
	$extra["wars"][] = War::getNamedWars("Closed Wars - Aggressor", "select * from zz_wars where aggressor = $warID and timeFinished is not null order by timeFinished desc");
	$extra["wars"][] = War::getNamedWars("Closed Wars - Defending", "select * from zz_wars where defender = $warID and timeFinished is not null order by timeFinished desc");
}

$filter = "";
switch ($key)
{
	case "corporation":
	case "alliance":
	case "faction":
		$filter = "{$key}ID = :id";
}
if ($filter != "") {
	$query = ["{$key}ID" => (int) $id, 'isVictim' => false, 'groupID' => [659, 30], 'pastSeconds' => (90 * 86400)];
	$query = MongoFilter::buildQuery($query);
	$hasSupers = $mdb->exists("killmails", $query);
	$extra["hasSupers"] = $hasSupers;

	$extra["supers"] = array();
	if ($pageType == "supers" && $hasSupers)
	{
		$data = array();
		$parameters = ["{$key}ID" => (int) $id, 'groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
		$data["titans"]["data"] = Stats::getTop("characterID", $parameters);
		$data["titans"]["title"] = "Titans";

		$parameters = ["{$key}ID" => (int) $id, 'groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
		$data["moms"]["data"] = Stats::getTop("characterID", $parameters);
		$data["moms"]["title"] = "Supercarriers";       

		Info::addInfo($data);
		$extra["supers"] = $data;
		$extra["hasSupers"] = sizeof($data["titans"]["data"]) || sizeof($data["moms"]["data"]);
	}
}

if ($key == "system") $statType = "solarSystemID";
else if ($key == "ship") $statType = "shipTypeID";
else $statType = "{$key}ID";
$statistics = $mdb->findDoc("statistics", ['type' => $statType, 'id' => (int) $id]);
$prevRanks = $mdb->findDoc("ranksProgress", ['cacheTime' => 36000, 'type' => $statType, 'id' => (int) $id], ['date' => 1]);
if ($prevRanks != null)
{
	$prevRanks["date"] = date("Y-m-d",$prevRanks["date"]->sec);
	$statistics["prevRanks"] = $prevRanks;
}

$groups = @$statistics["groups"];
if (is_array($groups) and sizeof($groups) > 0)
{
	Info::addInfo($groups);
	$g = [];
	foreach ($groups as $group) $g[$group["groupName"]] = $group;
	ksort($g);

	// Divide the stats into 4 columns...
	$chunkSize = ceil(sizeof($g) / 4);
	$statistics["groups"] = array_chunk($g, $chunkSize);
} else $statistics["groups"] = null;

$months = @$statistics["months"];
// Ensure the months are sorted in descending order
if (is_array($months) && sizeof($months) > 0)
{
	krsort($months);
	$statistics["months"] = array_values($months);

} else $statistics["months"] = null;

// Collect active PVP stats
$activePvP = Stats::getActivePvpStats($parameters);

$renderParams = array("pageName" => $pageName, "kills" => $kills, "losses" => $losses, "detail" => $detail, "page" => $page, "topKills" => $topKills, "mixed" => $mixedKills, "key" => $key, "id" => $id, "pageType" => $pageType, "solo" => $solo, "topLists" => $topLists, "corps" => $corpList, "corpStats" => $corpStats, "summaryTable" => $stats, "pager" => (sizeof($kills) + sizeof($losses) >= $limit), "datepicker" => true, "nextApiCheck" => $nextApiCheck, "apiVerified" => $apiVerified, "prevID" => $prevID, "nextID" => $nextID, "extra" => $extra, "statistics" => $statistics, "activePvP" => $activePvP);

$app->render("overview.html", $renderParams);


