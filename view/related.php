<?php

global $baseDir;

$systemID = (int) $system;
$relatedTime = (int) $time;

$json_options = json_decode($options, true);
if (!isset($json_options["A"])) $json_options["A"] = array();
if (!isset($json_options["B"])) $json_options["B"] = array();

$redirect = false;
if (isset($_GET["left"])) 
{
	$entity = $_GET["left"];
	if (!isset($json_options["A"])) $json_options["A"] = array();
	if (($key = array_search($entity, $json_options["B"])) !== false) unset($json_options["B"][$key]);
	if (!in_array($entity, $json_options["A"])) $json_options["A"][] = $entity;
	$redirect = true;
}
if (isset($_GET["right"]))
{
        $entity = $_GET["right"];
        if (!isset($json_options["B"])) $json_options["B"] = array();
	if (($key = array_search($entity, $json_options["A"])) !== false) unset($json_options["A"][$key]);
        if (!in_array($entity, $json_options["B"])) $json_options["B"][] = $entity;
        $redirect = true;
}
if ($redirect)
{
	$json = urlencode(json_encode($json_options));
	$url = "/related/$systemID/$relatedTime/o/$json/";
	$app->redirect($url, 302);
	die();
}

$systemName = Info::getSystemName($systemID);
$regionName = Info::getRegionName(Info::getRegionIDFromSystemID($systemID));
$unixTime = strtotime($relatedTime);
$time = date("Y-m-d H:i", $unixTime);

$exHours = 1;
if (((int) $exHours) < 1 || ((int) $exHours > 12)) $exHours = 1;

$key = "$systemID:$relatedTime:$exHours:" . json_encode($json_options);
$cache = new FileCache($baseDir . "/cache/related/");
$mc = $cache->get($key);
if (!$mc)
{
	$parameters = array("solarSystemID" => $systemID, "relatedTime" => $relatedTime, "exHours" => $exHours);
	$kills = Kills::getKills($parameters);
	$summary = Related::buildSummary($kills, $parameters, $json_options);
	$mc = array("summary" => $summary, "systemName" => $systemName, "regionName" => $regionName, "time" => $time, "exHours" => $exHours, "solarSystemID" => $systemID, "relatedTime" => $relatedTime, "options" => json_encode($json_options));
	$cache->set($key, $mc, 600);
}

$app->render("related.html", $mc);
