<?php

$entities = array();

if($_POST) $app->redirect("/search/".urlencode($_POST["searchbox"])."/");

if($search)
{
	$result = Info::findEntity($search);

	// if there is only one result, we redirect.
	if(count($result) == 1)
	{
		$type = str_replace("ID", "", $result[0]["type"]);
		$values = array_values($result[0]);
		$id = $result[0]["id"];
		$app->redirect("/$type/$id/");
		die();
	}

	$entities = [];
	foreach ($result as $row)
	{
		$entity = [];
		$entity["type"] = str_replace("ID", "", $row["type"]);
		$entity[$row["type"]] = $row["id"];
		$entity[$entity["type"] . "Name"] = $row["name"];
		if ($entity["type"] == "type") $entity["type"] = "item";
		if ($entity["type"] == "solarSystem") $entity["type"] = "system";
		$entities[] = $entity;
	}
	Info::addInfo($entities);
}

$app->render("search.html", array("data" => $entities));
