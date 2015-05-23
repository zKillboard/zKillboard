<?php

require_once "../init.php";

$minute = (int) date("i");
$hour = (int) date("H");
if ($hour != 13) exit();
if ($minute != 15) exit();

$json = CrestTools::getJSON("http://public-crest.eveonline.com/inventory/categories/7/");

foreach ($json["groups"] as $group)
{
	$href = $group["href"];
	$types = CrestTools::getJSON($href);
	foreach ($types["types"] as $type)
	{
		$typeID = getTypeID($type["href"]);
		$mdb->set("information", ['type' => 'typeID', 'id' => $typeID], ['fittable' => true]);
	}
	sleep(1);
}

function getTypeID($href)
{
        $ex = explode("/", $href);
        return (int) $ex[4];
}
