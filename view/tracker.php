<?php

$parameters = array();
$names = array();
$z = array();

getIDs("characterID", $parameters, UserConfig::get("tracker_character"));
getIDs("corporationID", $parameters, UserConfig::get("tracker_corporation"));
getIDs("allianceID", $parameters, UserConfig::get("tracker_alliance"));
getIDs("factionID", $parameters, UserConfig::get("tracker_faction"));
getIDs("shipTypeID", $parameters, UserConfig::get("tracker_item"));
getIDs("solarSystemID", $parameters, UserConfig::get("tracker_system"));
getIDs("regionID", $parameters, UserConfig::get("tracker_region"));
if(empty($parameters)) 
{
	$app->render("error.html", array("message" => "Nothing to track, please add entities to the tracker from your account page."));
	return;
}

GetNames("character", $names, UserConfig::get("tracker_character"));
GetNames("corporation", $names, UserConfig::get("tracker_corporation"));
GetNames("alliance", $names, UserConfig::get("tracker_alliance"));
GetNames("faction", $names, UserConfig::get("tracker_faction"));
GetNames("ship", $names, UserConfig::get("tracker_item"));
GetNames("systems", $names, UserConfig::get("tracker_system"));
GetNames("regions", $names, UserConfig::get("tracker_region"));

$parameters["combined"] = true;
$limit = 50;
$parameters["limit"] = $limit;
$parameters["page"] = $page;

$pageTitle = "Tracking";

$kills = Kills::getKills($parameters);

// Flag losses as red
unset($parameters["limit"]);
unset($parameters["combined"]);
unset($parameters["page"]);
foreach($parameters as $columnName=>$ids) {
	foreach($ids as $id) {
        $z[] = $id;
		$kills = Kills::mergeKillArrays($kills, array(), $limit, $columnName, $id);
	}
}
$imp = implode(",", $z);
$st = []; 
//Db::query("SELECT s.groupID AS groupID, SUM(s.destroyed) AS destroyed, SUM(s.lost) AS lost, SUM(s.pointsDestroyed) AS pointsDestroyed, SUM(s.pointsLost) AS pointsLost, SUM(s.iskDestroyed) AS iskDestroyed, SUM(s.iskLost) as iskLost, c.groupName AS groupName FROM zz_stats s JOIN ccp_invGroups c ON s.groupID = c.groupID WHERE s.typeID IN ($imp) GROUP BY s.groupID ORDER BY c.groupName");
$cnt = 0;
$cnid = 0;
$stats = array();
$totalcount = ceil(count($st) / 4);
foreach($st as $q)
{
    if($cnt == $totalcount)
    {
        $cnid++;
        $cnt = 0;
    }
    $stats[$cnid][] = $q;
    $cnt++;
}
$app->render("tracker.html", array("kills" => $kills, "pageTitle" => $pageTitle, "tracking" => $names, "page" => $page, "summaryTable" => $stats, "pager" => true));

function getIDs($filterName, &$ids, $array) {
    if (is_null($array) || sizeof($array) == 0) return;
    $filter = array();
    foreach ($array as $row) {
        $filter[] = $row["id"];
    }
    $ids[$filterName] = $filter;
}

function getNames($filterName, &$names, $array) {
    if (is_null($array) || sizeof($array) == 0) return;
    $filter = array();
    foreach ($array as $row) {
        $filter[] = $row["name"];
    }
    $names[$filterName] = $filter;
}
