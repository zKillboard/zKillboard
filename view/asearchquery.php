<?php

global $mdb, $redis;

$_POST = $_GET;
$query = [];
$query = buildQuery($query, "location");
$query = buildQuery($query, "neutrals");
$query = buildQuery($query, "attackers", false);
$query = buildQuery($query, "victims", true);

getLabelGroup("highsec");
if (isset($_POST['labels'])) {
    $l = $_POST['labels'];
    $labels = [];
    foreach ($l as $label) {
        $group = getLabelGroup($label);
        if ($group != null) {
            if (!(isset($labels[$group]))) $labels[$group] = [];
            $labels[$group][] = $label;
        }
    }
    foreach ($labels as $group => $search) $query[] = ['labels' => ['$in' => $search]];
}

if (sizeof($query) == 0) $query = [];
else if (sizeof($query) == 1) $query = $query[0];
else $query = ['$and' => $query];
Log::log("final query: " . print_r($query, true));

$sort = ['killID' => -1];
$coll = ['oneWeek', 'ninetyDays', 'killmails'];
foreach ($coll as $col) {
    $result = $mdb->find($col, $query, $sort, 50);
    if (sizeof($result) >= 50) break;
}

// Declare out json return type
$app->contentType('application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$arr = [];
foreach ($result as $row) {
    $killID = $row['killID'];
    $redis->setex("zkb:killlistrow:" . $killID, 60, "true");
    $arr[] = $killID;
}

echo json_encode($arr, true);

function buildQuery($queries, $key, $isVictim = null) {
    $query = buildFromArray($key, $isVictim);
    if ($query != null && sizeof($query) > 0) $queries[] = $query;
    return $queries;
}


function buildFromArray($key, $isVictim = null) {
    if (!isset($_POST[$key])) return null;
    $arr = $_POST[$key];
    $ret = [];
    $param = [];
    foreach ($arr as $row) {
        if ($row['type'] == 'systemID') $row['type'] = 'solarSystemID';
        if ($row['type'] == 'shipID') $row['type'] = 'shipTypeID';

        //if (!in_array($row['type'], $types)) continue;
        //$param = [$row['type'] => (int) $row['id']];
        $param[$row['type']] = (int) $row['id'];
        if ($isVictim === false) $param['kills'] = true;
        else if ($isVictim === true) $param['losses'] = true;
        //if (sizeof($q) > 0) $ret[] = $q;
    }
    return MongoFilter::buildQuery($param, true);
    if (sizeof($ret) == 0) return null;
    if (sizeof($ret) == 1) return $ret[0];
    return ['$and' => $ret];
}

$types = [
    'region_id',
    'solar_system_id',
    'item_id',
    'group_id',
    'faction_id',
    'alliance_id',
    'corporation_id',
    'character_id',
    'category_id',
    'location_id',
    'constellation_id',
]; // war_id is excluded

function getLabelGroup($label) {
    foreach (AdvancedSearch::$labels as $group => $labels) {
        if (in_array($label, $labels)) return $group;
    }
    return null;
}
