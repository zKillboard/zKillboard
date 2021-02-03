<?php

global $mdb, $redis;

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

$validSortBy = ['date' => 'killID', 'isk' => 'zkb.totalValue', 'involved' => 'attackerCount'];
$validSortDir = ['asc' => 1, 'desc' => -1];

$_POST = $_GET;
$query = [];

$queryType = (string) @$_POST['queryType'];
if ($queryType == "") $queryType = "kills";
unset($_POST['queryType']);

$groupType = (string) @$_POST['groupType'];
unset($_POST['groupType']);

$query = buildQuery($query, "location");
$query = buildQuery($query, "neutrals");
$query = buildQuery($query, "attackers", false);
$query = buildQuery($query, "victims", true);

$query = parseDate($query, 'start');
$query = parseDate($query, 'end');
$startTime = (int) @$query['start'];
$endTime = (int) @$query['end'];
if ($startTime > time()) $startTime = time();
if ($endTime == 0 || $endTime > time()) $endTime = time();
unset($query['start']);
unset($query['end']);

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

$page = (isset($_POST['radios']['page']) ? max(1, min(10, (int) @$_POST['radios']['page'])) - 1 : 0);
$sortKey = (isset($validSortBy[$_POST['radios']['sort']['sortBy']]) ? $validSortBy[$_POST['radios']['sort']['sortBy']] : 'killID' );
$sortBy = (isset($validSortDir[$_POST['radios']['sort']['sortDir']]) ? $validSortDir[$_POST['radios']['sort']['sortDir']] : -1 );
$sort = [$sortKey => $sortBy];
$coll = ['killmails'];
if ($sortKey == 'killID' && $sortBy == -1 && @$query['hasDateFilter'] != true) {
    $coll = ['oneWeek', 'ninetyDays', 'killmails'];
}
unset($query['hasDateFilter']);

if (sizeof($query) == 0) $query = [];
else if (sizeof($query) == 1) $query = $query[0];
else $query = ['$and' => $query];

// Declare out json return type

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$arr = [];
if ($queryType == "kills") {
    $app->contentType('application/json; charset=utf-8');
    foreach ($coll as $col) {
        $result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(50 * $page)->limit(50));
        if (sizeof($result) >= 50) break;
    }
    $arr['kills'] = [];
    foreach ($result as $row) {
        $killID = $row['killID'];
        $redis->setex("zkb:killlistrow:" . $killID, 60, "true");
        $arr['kills'][] = $killID;
    }
} else if ($queryType == 'count') {
    $app->contentType('application/json; charset=utf-8');
    foreach ($coll as $col) {
        $result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(50 * $page)->limit(50));
        if (sizeof($result) >= 50) break;
    }
    if (($endTime - $startTime) <= 604800) { 
        $arr['count'] = $mdb->getCollection($col)->count($query);
    } else $arr['count'] = '';
} else if ($queryType == "groups") {
    $app->contentType('text/html; charset=utf-8');
    $arr['top'] = [];
    if (($endTime - $startTime) <= 604800) {
        if (in_array($groupType . '_id', $types)) {
            $res = getTop($groupType . 'ID', $query);
            $app->render("components/asearch_top_list.html", ['topSet' => ['type' => $groupType, 'title' => 'Top ' . Util::pluralize(ucwords($groupType)), 'values' => $res]]);
        }
        /*$arr['top']['character'] = getTop('characterID', $query);
        $arr['top']['corporation'] = getTop('corporationID', $query);
        $arr['top']['alliance'] = getTop('allianceID', $query);
        $arr['top']['ship'] = getTop('shipTypeID', $query);
        $arr['top']['group'] = getTop('groupID', $query);
        $arr['top']['region'] = getTop('regionID', $query);
        $arr['top']['system'] = getTop('solarSystemID', $query);
        $arr['top']['location'] = getTop('locationID', $query);*/
    }
    return;
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


function getLabelGroup($label) {
    foreach (AdvancedSearch::$labels as $group => $labels) {
        if (in_array($label, $labels)) return $group;
    }
    return null;
}

function parseDate($query, $which) {
    $val = (string) @$_POST['epoch'][$which];
    if ($val == "") return $query;

    $time = strtotime($val);
    if ($time > time()) {
        $query[] = ['killID' => 0];
        return $query;
    }

    $killID = Info::findKillID($time, $which);
    if ($killID != null) {
        $query[] = ['killID' => [($which == 'start' ? '$gte' : '$lte') => $killID]];
        $query['hasDateFilter'] = true;
        $query[$which] = strtotime($val);
    }

    return $query;
}


function getTop($groupByColumn, $query, $cacheOverride = false, $addInfo = true)
{
    global $mdb, $longQueryMS;

    try {
    $hashKey = "Stats::getTop:q:$groupByColumn:".serialize($query);
    $result = null; // RedisCache::get($hashKey);
    if ($cacheOverride == false && $result != null) {
        return $result;
    }

    $killmails = $mdb->getCollection('killmails');

    if ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') {
        $keyField = "system.$groupByColumn";
    } elseif ($groupByColumn != 'locationID') {
        $keyField = "involved.$groupByColumn";
    } else {
        $keyField = $groupByColumn;
    }

    $id = $type = null;
    if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
        $type = "involved." . $groupByColumn;
    }

    $timer = new Timer();
    $pipeline = [];
    $pipeline[] = ['$match' => $query];
    if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
        $pipeline[] = ['$unwind' => '$involved'];
    }
    if ($type != null && $id != null) {
        //$pipeline[] = ['$match' => [$type => $id, 'involved.isVictim' => false]];
    }
    $pipeline[] = ['$match' => ['involved.isVictim' => true]];
    $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
    //$pipeline[] = ['$match' => $andQuery];
    $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$'.$keyField]]];
    $pipeline[] = ['$group' => ['_id' => '$_id.'.$groupByColumn, 'kills' => ['$sum' => 1]]];
    $pipeline[] = ['$sort' => ['kills' => -1]];
    $pipeline[] = ['$limit' => 100];
    $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

    $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]);
    $result = $rr['result'];

    $time = $timer->stop();
    if ($time > $longQueryMS) {
        global $uri;
        Log::log("getTop Long query (${time}ms): $hashKey $uri");
    }

    if ($addInfo) Info::addInfo($result);
    //RedisCache::set($hashKey, $result, isset($parameters['cacheTime']) ? $parameters['cacheTime'] : 900);

    return $result;
    } catch (Exception $ex) { Log::log(print_r($ex, true)); return []; }
}

