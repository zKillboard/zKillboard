<?php

$page = 1;
$pageTitle = "";
$pageType = "index";
$requestUriPager = "";
$serverName = $_SERVER["SERVER_NAME"];
global $baseAddr, $fullAddr;
if ($serverName != $baseAddr) {
	$numDays = 7;
	$p = Subdomains::getSubdomainParameters($serverName);
	$page = max(1, min(25, $page));
	$p["page"] = $page;

	$columnName = key($p);
	$id = (int) reset($p);

	if (sizeof($p) <= 1) $app->redirect($fullAddr, 302);

	$topPoints = array();
	$topPods = array();

	$p["kills"] = true;
	$p["pastSeconds"] = ($numDays*86400);

	$top = array();
	$top[] = Info::doMakeCommon("Top Characters", "characterID", Stats::getTopPilots($p));
	$top[] = ($columnName != "corporationID" ? Info::doMakeCommon("Top Corporations", "corporationID", Stats::getTopCorps($p)) : array());
	$top[] = ($columnName != "corporationID" && $columnName != "allianceID" ? Info::doMakeCommon("Top Alliances", "allianceID", Stats::getTopAllis($p)) : array());
	$top[] = Info::doMakeCommon("Top Ships", "shipTypeID", Stats::getTopShips($p));
	$top[] = Info::doMakeCommon("Top Systems", "solarSystemID", Stats::getTopSystems($p));

	$requestUriPager = str_replace("ID", "", $columnName) . "/$id/";

	$p["limit"] = 5;
	$topIsk = Stats::getTopIsk($p);
	unset($p["pastSeconds"]);
	unset($p["kills"]);

	// get latest kills
	$killsLimit = 50;
	$p["limit"] = $killsLimit;
	$kills = Kills::getKills($p);

	$kills = Kills::mergeKillArrays($kills, array(), $killsLimit, $columnName, $id);

	Info::addInfo($p);
	$pageTitle = array();
	foreach($p as $key=>$value) 
	{
		if (strpos($key, "Name") !== false) $pageTitle[] = $value;
	}
	$pageTitle = implode(",", $pageTitle);
	$pageType = "subdomain";
} else {
	$topPoints = array();
	$topIsk = Stats::getTopIsk(array('cacheTime' => (15 * 60), "pastSeconds" => (7*86400), "limit" => 5));
	$topPods = array();

	$top = array();
	$top[] = json_decode(Storage::retrieve("TopChars", [], 900), true);
	$top[] = json_decode(Storage::retrieve("TopCorps", [], 900), true);
	$top[] = json_decode(Storage::retrieve("TopAllis", [], 900), true);
	$top[] = json_decode(Storage::retrieve("TopShips", [], 900), true);
	$top[] = json_decode(Storage::retrieve("TopSystems", [], 900), true);

	// get latest kills
	$kills = Kills::getKills(array('cacheTime' => 60, "limit" => 50));
}

$app->render("index.html", array("topPods" => $topPods, "topIsk" => $topIsk, "topPoints" => $topPoints, "topKillers" => $top, "kills" => $kills, "page" => $page, "pageType" => $pageType, "pager" => true, "pageTitle" => $pageTitle, "requestUriPager" => $requestUriPager));
