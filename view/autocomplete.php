<?php

global $redis;

$imageMap = ['typeID' => 'Type/%1$d_32.png', 'characterID' => 'Character/%1$d_32.jpg', 'corporationID' => 'Corporation/%1$d_32.png', 'allianceID' => 'Alliance/%1$d_32.png', 'factionID' => 'Alliance/%1$d_32.png'];
    
if ($app->request()->isPost()) {
    $search = $app->request()->post('query');
}

if (!(isset($entityType))) $entityType = null;
$search = strtolower($search);
$low = "[$search\x00";
        
$exactMatch = [];
$partialMatch = [];
$retVal = [];
$types = ['typeID:flag', 'regionID', 'solarSystemID', 'allianceID', 'allianceID:flag', 'corporationID', 'corporationID:flag', 'characterID', 'typeID'];
$timer = new Timer();
foreach ($types as $type) {
	if ($entityType != null && $entityType != $type) continue;

        $result = $redis->zRangeByLex("search:$type", $low, "+", 0, 9);

        $next = [];
	$searchType = $type;
	$type = str_replace(":flag", "", $type);
	foreach ($result as $row) {
		$split = explode("\x00", $row);
		if (substr($split[0], 0, strlen($search)) != $search) continue;
		$id = $split[1];
		$name = Info::getInfoField($type, $id, 'name');
		$image = isset($imageMap[$type]) ? $imageMap[$type] : '';
		$image = sprintf($image, $id);
		if ($searchType == 'typeID:flag') $searchType = 'ship';
		if ($searchType == 'allianceID:flag') $searchType = 'alliance';
		if ($searchType == 'corporationID:flag') $searchType = 'corporation';
		if ($searchType == 'typeID') $searchType = 'item';
		if ($searchType == 'solarSystemID') $searchType = 'system';
		if ($searchType == 'system') {
			$regionID = Info::getInfoField('solarSystemID', $id, 'regionID');
			$regionName = Info::getInfoField('regionID', $regionID, 'name');
			$name = "$name ($regionName)";
		}
		if (strtolower($name) === $search) $exactMatch[] = ['id' => $id, 'name' => $name, 'type' => str_replace("ID", "", $searchType), 'image' => $image];
		else $partialMatch[] = ['id' => $id, 'name' => $name, 'type' => str_replace("ID", "", $searchType), 'image' => $image];
	}
}   

$result = array_merge($exactMatch, $partialMatch);
if (sizeof($result) > 15) $result = array_slice($result, 0, 15);

// Declare out json return type
$app->contentType('application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

echo json_encode($result);
