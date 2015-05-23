<?php

if (!is_numeric($id))
{
    $id = Info::getItemId($id);
    if ($id > 0) header("Location: /item/$id/");
    else header("Location: /");
    die();
}

$info = Db::queryRow("select typeID, typeName, description from ccp_invTypes where typeID = :id", array(":id" => $id), 3600);
$info["description"] = str_replace("<br>", "\n", @$info["description"]);
$info["description"] = strip_tags(@$info["description"]);
$info["price"] = Price::getItemPrice($id, date("Ymd"));

global $mdb;
$cursor = $mdb->getCollection("killmails")->find(['involved.shipTypeID' => (int) $id]);
$hasKills = $cursor->hasNext();

$info["attributes"] = array(); 

$info["market"] = Db::query("select * from zz_item_price_lookup where typeID = :typeID order by priceDate desc limit 30", array(":typeID" => $id));

$kills = $mdb->find("itemmails", ['typeID' => (int) $id], ['killID' => -1], 50);
$victims = [];
foreach($kills as $row) 
{
	$kill = $mdb->findDoc("killmails", ['killID' => $row["killID"]]);
	$victim = $kill["involved"][0];
	$victim["destroyed"] = $row["killID"];
	$victims[] = $victim;
}
Info::addInfo($victims);

$app->render("item.html", array("info" => $info, "hasKills" => $hasKills, 'kills' => $victims));
