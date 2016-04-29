<?php

require_once "../init.php";

if ($redis->get("tq:itemsPopulated") != true)
{
        Util::out("Waiting for items to be populated...");
        exit();
}

$redisMD5 = $redis->get("tqSDE:MD5");
if ($redisMD5 != null && date('i') != 0) exit();

$sdeMD5 = file_get_contents("https://www.fuzzwork.co.uk/dump/mysql-latest.tar.bz2.md5");

if ($sdeMD5 == $redisMD5) exit();

Util::out("New SDE detected, importing now");

getCSV("https://www.fuzzwork.co.uk/dump/latest/invNames.csv.bz2", "updateLocationID");

$redis->set("tqSDE:MD5", $sdeMD5);


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

	$locationID = (int) $fields['itemid'];
	$name = $fields['itemname'];
	$name = str_replace('"', '', $name);
	$query = ['type' => 'locationID', 'id' => $locationID];
	if (!$mdb->exists("information", $query)) $mdb->save("information", $query);
	$mdb->set("information", $query, ['name' => $name]);
}

function nextRow(&$csv) {
	$next = strpos($csv, "\n");
	$text = substr($csv, 0, $next);
	$csv = trim(substr($csv, min($next + 1, strlen($csv))));
	$split = explode(",", $text);
	return $split;
}

function parseCSV(&$csv, $function) {
	global $redis;

	$fieldNames = nextRow($csv);

	while (strlen($csv) > 0) {
		$fields = nextRow($csv);
		if (sizeof($fields) > 0) {
			$next = [];
			foreach ($fieldNames as $i=>$key)
			{
				$key = strtolower(trim($key));
				$value = trim($fields[$i]);
				$next[$key] = $value;
			}
			$function($next);
			$redis->get('-'); // Keep redis alive
		}
	}
}
