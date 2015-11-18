<?php

require_once "../init.php";

if (date('i') != 0) exit();

$redisMD5 = $redis->get("tqSDE:MD5");
$sdeMD5 = file_get_contents("https://www.fuzzwork.co.uk/dump/mysql-latest.tar.bz2.md5");

if ($sdeMD5 == $redisMD5) exit();

Util::out("New SDE detected, importing now");
updateSlots();
$redis->setex("tqSDE:MD5", (86400 * 7), $sdeMD5);

function updateSlots() {
	global $mdb, $redis;

	$file = $redis->get("RC:dgmTypeAttributes.csv.bz2");
	if ($file == null) {
		$file = file_get_contents("https://www.fuzzwork.co.uk/dump/latest/dgmTypeAttributes.csv.bz2");
		$redis->setex("RC:dgmTypeAttributes.csv.bz2", 900, $file);
	}
	$csv = bzdecompress($file);
	$dgmTypeAttributes = csvToArray($csv);
	foreach ($dgmTypeAttributes as $row) {
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
}

function csvToArray($csv) {
	$rows = split("\n", $csv);
	$row1 = $rows[0];
	$fieldNames = split(",", $row1);
	$fieldCount = sizeof($fieldNames);
	array_shift($rows);
	$ret = [];
	foreach ($rows as $row) {
		$fields = split(",", $row);
		if (sizeof($fields) > 0) {
			$next = [];
			for ($i = 0; $i < $fieldCount; $i++) {
				$next[$fieldNames[$i]] = @$fields[$i];
			}
			$ret[] = $next;
		}
	}
	return $ret;
}
