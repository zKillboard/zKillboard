<?php

$parameters = array("limit" => 10, "kills" => true, "pastSeconds" => 3600, "cacheTime" => 30);
$alltime = false;

$topKillers[] = array("type" => "character", "data" => Stats::getTopPilots($parameters, $alltime));
$topKillers[] = array("type" => "corporation", "data" => Stats::getTopCorps($parameters, $alltime));
$topKillers[] = array("type" => "alliance", "data" => Stats::getTopAllis($parameters, $alltime));

$topKillers[] = array("type" => "faction", "data" => Stats::getTopFactions($parameters, $alltime));
$topKillers[] = array("type" => "system", "data" => Stats::getTopSystems($parameters, $alltime));
$topKillers[] = array("type" => "region", "data" => Stats::getTopRegions($parameters, $alltime));

$topKillers[] = array("type" => "ship", "data" => Stats::getTopShips($parameters, $alltime));
$topKillers[] = array("type" => "group", "data" => Stats::getTopGroups($parameters, $alltime));
//$topKillers[] = array("type" => "weapon", "data" => Stats::getTopWeapons($parameters, $alltime));

unset($parameters["kills"]);
$parameters["losses"] = true;
$topLosers[] = array("type" => "character", "ranked" => "Losses", "data" => Stats::getTopPilots($parameters, $alltime));
$topLosers[] = array("type" => "corporation", "ranked" => "Losses", "data" => Stats::getTopCorps($parameters, $alltime));
$topLosers[] = array("type" => "alliance", "ranked" => "Losses", "data" => Stats::getTopAllis($parameters, $alltime));

$topLosers[] = array("type" => "faction", "ranked" => "Losses", "data" => Stats::getTopFactions($parameters, $alltime));
$topLosers[] = array("type" => "ship", "ranked" => "Losses", "data" => Stats::getTopShips($parameters, $alltime));
$topLosers[] = array("type" => "group", "ranked" => "Losses", "data" => Stats::getTopGroups($parameters, $alltime));

$app->render("lasthour.html", array("topKillers" => $topKillers, "topLosers" => $topLosers, "time" => date("H:i")));
