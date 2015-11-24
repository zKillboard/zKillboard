<?php

require_once '../init.php';

global $redis;

if (date('i') != 0) exit();
if (date('H') == 10) {
	// Purge, just in case of name changes and what not
	$keys = $redis->keys("search:*");
	foreach ($keys as $key) $redis->del($key);
}

$entities = $mdb->getCollection('information')->find();

foreach ($entities as $entity) {
	$type = $entity['type'];
	$id = $entity['id'];
	$name = @$entity['name'];
	if ($name == '') {
		continue;
	}

	$flag = '';
	switch ($type) {
		case 'locationID':
		case 'warID':
			continue;
		case 'corporationID':
			$flag = @$entity['ticker'];
			break;
		case 'allianceID':
			$flag = @$entity['ticker'];
			break;
		case 'typeID':
			if ($mdb->exists('killmails', ['involved.shipTypeID' => $id])) 	$flag = strtolower($name);
			if ($entity['published'] != true && $flag == '') continue;
			break;
		case 'solarSystemID':
			$regionID = Info::getInfoField('solarSystemID', $id, 'regionID');
			$regionName = Info::getInfoField('regionID', $regionID, 'name');
			$name = "$name ($regionName)";
			break;
	}

	$redis->zAdd("search:$type", 0, strtolower($name) . "\xFF$id");
	if (strlen($flag) > 0) $redis->zAdd("search:$type:flag", 0, "$flag\xFF$id");
}
