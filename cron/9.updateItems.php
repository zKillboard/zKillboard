<?php

require_once "../init.php";

if (date('i') != 45) exit();

$assign = ['capacity', 'name', 'portionSize', 'mass', 'volume', 'description', 'radius', 'published'];
$rows = $mdb->getCollection("information")->find(['type' => 'typeID']);
foreach ($rows as $row) {
	$typeID = (int) $row['id'];
	$lastCrestUpdate = @$row['lastCrestUpdate'];
	if ($lastCrestUpdate != null && $lastCrestUpdate->sec > (time() - 86400)) continue;
	$crest = CrestTools::getJSON("https://public-crest.eveonline.com/types/$typeID/");

	foreach ($assign as $key) {
		if (isset($crest[$key])) $row[$key] = $crest[$key];
	}
	$row['lastCrestUpdate'] = $mdb->now();
	$mdb->save("information", $row);
}
