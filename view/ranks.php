<?php

global $mdb;

if (!in_array($pageType, array("recent", "alltime"))) $app->notFound();
if (!in_array($subType, array("killers", "losers"))) $app->notFound();

$rankType = $pageType == "recent" ? "recentOverallRank" : "overallRank";
$pageTitle = $pageType == "recent" ? "Ranks - Recent (Past 90 Days)" : "Alltime Ranks";
$tableTitle = $pageType == "recent" ? "Recent Rank" : "Alltime Rank";

$types = array("pilot" => "characterID", "corp" => "corporationID", "alli" => "allianceID", "faction" => "factionID");
$names = array("character" => "Characters", "corp" => "Corporations", "alli" => "Alliances", "faction" => "Factions");
$ranks = array();
foreach ($types as $type=>$column) {
	$r = $mdb->find("statistics", ['cacheTime' => 7200, 'type' => $column, $rankType => ['$ne' => null]], [$rankType => 1], 10);
	$result = [];
	foreach ($r as $row)
	{
		unset($row["groups"]);
		unset($row["months"]);
		unset($row["topAllTime"]);
		$row[$column] = $row['id'];
		$result[] = $row;
	}
	if ($type == "pilot") $type = "character";
	Info::addInfo($result);
	$ranks[] = array("type" => $type, "data" => $result, "name" => $names[$type]);
}

Info::addInfo($ranks);

$app->render("ranks.html", array("ranks" => $ranks, "pageTitle" => $pageTitle, "tableTitle" => $tableTitle, "pageType" => $pageType, "subType" => $subType));
