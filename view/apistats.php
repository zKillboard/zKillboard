<?php

global $apiWhiteList, $mdb;

try {

	// Ensure the requesting server is requesting a compressed version
	$encoding = @$_SERVER["HTTP_ACCEPT_ENCODING"];
	$imploded = explode(",", $encoding);
	if (array_search("gzip", $imploded) === false && array_search("deflate", $imploded) === false)
	{
		header("HTTP/1.0 406 Non-acceptable encoding.  Please use gzip or deflate");
		die();
	}

	$parameters = Util::convertUriToParameters();

	// Enforcement
	if (sizeof($parameters) < 2) die("Invalid request.  Must provide at least two request parameters");

	// At least one of these modifiers is required
	$requiredM = array("characterID", "corporationID", "allianceID", "factionID", "shipTypeID", "groupID", "solarSystemID", "solo", "w-space", "warID", "killID");
	$hasRequired = false;
	$hasRequired |= in_array(IP::get(), $apiWhiteList);
	foreach($requiredM as $required) $hasRequired |= array_key_exists($required, $parameters);
	
	$array = $mdb->findDoc("statistics", ['type' => $type, 'id' => (int) $id]);
	unset($array["_id"]);
	$array["activepvp"] = Stats::getActivePvpStats($parameters);
	$array["info"] = $mdb->findDoc("information", ['type' => $type, 'id' => (int) $id]);
	unset($array["info"]["_id"]);

	header("Access-Control-Allow-Origin: *");
	header("Access-Control-Allow-Methods: GET");

	if(isset($_GET["callback"]) && Util::isValidCallback($_GET["callback"]) )
	{
		$app->contentType("application/javascript; charset=utf-8");
		header("X-JSONP: true");
		echo $_GET["callback"] . "(" . json_encode($array) .")";
	}
	else
	{
		$app->contentType("application/json; charset=utf-8");
		if(isset($parameters["pretty"]))
			echo json_encode($array, JSON_PRETTY_PRINT);
		else
			echo json_encode($array);
	}
} catch (Exception $ex )
{
	header("HTTP/1.0 503 Server error.");
	die();
	print_r($ex);
}
