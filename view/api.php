<?php

global $apiWhiteList;

try {

	// Ensure the requesting server is requesting a compressed version
	$encoding = @$_SERVER["HTTP_ACCEPT_ENCODING"];
	$imploded = explode(",", $encoding);
	if (array_search("gzip", $imploded) === false && array_search("deflate", $imploded) === false)
	{
		header("HTTP/1.0 406 Non-acceptable encoding.  Please use gzip or deflate");
		die();
	}

	//make sure the requester is not being a naughty boy
	Util::scrapeCheck();

	$parameters = Util::convertUriToParameters();

	// Enforcement
	if (sizeof($parameters) < 2) die("Invalid request.  Must provide at least two request parameters");

	// At least one of these modifiers is required
	$requiredM = array("characterID", "corporationID", "allianceID", "factionID", "shipTypeID", "groupID", "solarSystemID", "regionID", "solo", "w-space", "warID", "killID");
	$hasRequired = false;
	$hasRequired |= in_array(IP::get(), $apiWhiteList);
	foreach($requiredM as $required) {
		$hasRequired |= array_key_exists($required, $parameters);
	}
	if (!isset($parameters["killID"]) && !$hasRequired) 
	{
		header("Error: Must pass at least two required modifier.  Please read API Information.");
		http_response_code(406);
		exit;
	}

	$return = Feed::getKills($parameters);

	$array = array();
	foreach($return as $json)
	{
		$result = json_decode($json, true);
		if (isset($parameters["zkbOnly"]) && $parameters["zkbOnly"] == true)
		{
			if (is_array($result)) foreach($result as $key=>$value) if ($key != "killID" && $key != "zkb") unset($result[$key]);
		}
		$array[] = $result;
	}
	$app->etag(md5(serialize($return)));
	$app->expires("+1 hour");
	header("Access-Control-Allow-Origin: *");
	header("Access-Control-Allow-Methods: GET");

	if(isset($parameters["xml"]))
	{
		$app->contentType("text/xml; charset=utf-8");
		echo XmlWrapper::xmlOut($array, $parameters);
	}
	elseif(isset($_GET["callback"]) && Util::isValidCallback($_GET["callback"]) )
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
	print_r($ex);
	header("HTTP/1.0 503 Server error.");
	die();
}
