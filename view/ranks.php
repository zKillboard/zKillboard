<?php
exit("These rank pages have been temporarily disabled");

if (!in_array($pageType, array("recent", "alltime"))) $app->notFound();
if (!in_array($subType, array("killers", "losers"))) $app->notFound();

$table = $pageType == "recent" ? "zz_ranks_recent" : "zz_ranks";
$pageTitle = $pageType == "recent" ? "Ranks - Recent (Past 90 Days)" : "Alltime Ranks";
$tableTitle = $pageType == "recent" ? "Recent Rank" : "Alltime Rank";

$rankColumns = $subType == "killers" ? "(sdRank <= 10 or pdRank <= 10 or idRank <= 10 or overallRank <= 10)" : "(slRank <= 10 or plRank <= 10 or ilRank <= 10)";

$types = array("pilot" => "characterID", "corp" => "corporationID", "alli" => "allianceID", "faction" => "factionID");
$names = array("character" => "Characters", "corp" => "Corporations", "alli" => "Alliances", "faction" => "Factions");
$ranks = array();
foreach ($types as $type=>$column) {
	$result = Db::query("select distinct typeID $column, r.* from $table r where type = '$type' and $rankColumns order by overallRank");
	if ($type == "pilot") $type = "character";
	$ranks[] = array("type" => $type, "data" => $result, "name" => $names[$type]);
}

Info::addInfo($ranks);

$app->render("ranks.html", array("ranks" => $ranks, "pageTitle" => $pageTitle, "tableTitle" => $tableTitle, "pageType" => $pageType, "subType" => $subType));
