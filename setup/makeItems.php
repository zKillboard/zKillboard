<?php

require_once "../init.php";

$items = $mdb->find("information", ['type' => 'typeID']);

$arr = [];
foreach ($items as $item)
{
	$row = [];
	$row['typeID'] = (int) $item['id'];
	$row['groupID'] = (int) $item['groupID'];
	$row['name'] = $item['name'];
	$arr[] = $row;
}

$json = json_encode($arr);
file_put_contents("$baseDir/setup/items.json", $json);
