<?php

require_once "../init.php";

$redisMD5 = $redis->get("tqSDE:MD5");
if ($redisMD5 != null && date('i') != 0) exit();

$sdeMD5 = file_get_contents("https://www.fuzzwork.co.uk/dump/mysql-latest.tar.bz2.md5");

if ($sdeMD5 == $redisMD5) exit();

Util::out("New SDE detected, importing now");

getCSV("https://www.fuzzwork.co.uk/dump/latest/dgmTypeAttributes.csv.bz2", "updateSlots");
getCSV("https://www.fuzzwork.co.uk/dump/latest/invNames.csv.bz2", "updateLocationID");

$redis->setex("tqSDE:MD5", (86400 * 7), $sdeMD5);


function getCSV($url, $method) {
	global $mdb, $redis;

	$file = $redis->get("RC:$url");
	if ($file == null) {
		$file = file_get_contents($url);
		$redis->setex("RC:$url", 900, $file);
	}
	$csv = bzdecompress($file);
	Util::out("Parsing $url");
	parseCSV($csv, $method);
}

function updateLocationID($fields) {
	global $mdb, $redis;

	$locationID = (int) $fields['ITEMID'];
	$name = $fields['ITEMNAME'];
	$name = str_replace('"', '', $name);
	$query = ['type' => 'locationID', 'id' => $locationID];
	if (!$mdb->exists("information", $query)) $mdb->save("information", $query);
	$mdb->set("information", $query, ['name' => $name]);
	$redis->hMSet("tq:locationID:$locationID", ['type' => 'locationID', 'name' => $name, 'id' => $locationID]);
}

function updateSlots($row) {
	global $redis, $mdb;

	$typeID = $row['TYPEID'];
	$value = max($row['VALUEINT'], $row['VALUEFLOAT']);
	switch($row['ATTRIBUTEID']) {
		case 12:
			$mdb->set("information", ['type' => 'typeID', 'id' => (int) $typeID], ['lowSlotCount' => (int) $value]);
			$mdb->getCollection("information")->update(['type' => 'typeID', 'id' => (int) $typeID], ['$unset' => ['lowSlotCounts' => 1]]);
			break;
		case 13:
			$mdb->set("information", ['type' => 'typeID', 'id' => (int) $typeID], ['midSlotCount' => (int) $value]);
			break;
		case 14:
			$mdb->set("information", ['type' => 'typeID', 'id' => (int) $typeID], ['highSlotCount' => (int) $value]);
			break;
		case 1137:
			$mdb->set("information", ['type' => 'typeID', 'id' => (int) $typeID], ['rigSlotCount' => (int) $value]);
			break;
		case 331:
			$mdb->set("information", ['type' => 'typeID', 'id' => (int) $typeID], ['implantSlot' => (int) $value]);
			break;
	}
}

function nextRow(&$csv) {
	$next = strpos($csv, "\n");
	$text = substr($csv, 0, $next);
	$csv = trim(substr($csv, min($next + 1, strlen($csv))));
	$split = explode(",", $text);
	return $split;
}

function parseCSV(&$csv, $function) {
	$fieldNames = nextRow($csv);
	$fieldCount = sizeof($fieldNames);

	while (strlen($csv) > 0) {
		$fields = nextRow($csv);
		if (sizeof($fields) > 0) {
			$next = [];
			for ($i = 0; $i < $fieldCount; $i++) {
				$next[$fieldNames[$i]] = @$fields[$i];
			}
			$function($next);
		}
	}
}
