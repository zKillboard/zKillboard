<?php

$numDays = 7;

$campaign = Db::queryRow("select * from zz_campaigns where uri = :uri", array(":uri" => $uri), 1);
if ($campaign == null) $app->redirect("/", 302);

$title = "Campaign: " . $campaign["title"];
$subTitle = $campaign["subTitle"];
$p = json_decode($campaign["definition"], true);

$summary = Summary::getSummary("system", "solarSystemID", $p, 30000142, $p, true);

$topPoints = array();
$topPods = array();

$top = array();
$top[] = Info::doMakeCommon("Top Characters", "characterID", Stats::getTopPilots($p, true));
$top[] = Info::doMakeCommon("Top Corporations", "corporationID", Stats::getTopCorps($p, true));
$top[] = Info::doMakeCommon("Top Alliances", "allianceID", Stats::getTopAllis($p, true));
$top[] = Info::doMakeCommon("Top Ships", "shipTypeID", Stats::getTopShips($p, true));
$top[] = Info::doMakeCommon("Top Systems", "solarSystemID", Stats::getTopSystems($p, true));

$p["pastSeconds"] = ($numDays*86400);
$p["limit"] = 5;
$topIsk = Stats::getTopIsk($p, true);
$topIsk["title"] = "Most Valuable Kills";
unset($p["pastSeconds"]);

// get latest kills
$killsLimit = 50;
$p["limit"] = $killsLimit;
if (isset($page) && $page > 0 && $page < 100) $p["page"] = $page;
else $page = 1;
$kills = Kills::getKills($p);

$app->render("campaign.html", array("topPods" => $topPods, "topIsk" => $topIsk, "topPoints" => $topPoints, "topKillers" => $top, "kills" => $kills, "page" => 1, "pageType" => "kills", "pager" => true, "requesturi" => "/campaign/burnjita3/", "page" => $page, "detail" => $summary, "pageTitle" => $title, "subTitle" => $subTitle));
