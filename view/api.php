<?php

try {
	$parameters = Util::convertUriToParameters();

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
	header("HTTP/1.0 503 Server error.");
	die();
}
