<?php

use cvweiss\redistools\RedisCache;

global $mdb, $redis;

try {

    $types = [
        'character',
        'corporation',
        'alliance',
        'group',
        'region',
        'solarSystem',
        'shipType',
        'faction',
        'category',
        'location',
        'constellation',
    ]; // war_id is currently excluded

    $validSortBy = ['date' => 'killID', 'isk' => 'zkb.totalValue', 'involved' => 'attackerCount'];
    $validSortDir = ['asc' => 1, 'desc' => -1];

    $_POST = $_GET;
    $query = [];

    $queryType = (string) @$_POST['queryType'];
    if ($queryType == "") $queryType = "kills";
    unset($_POST['queryType']);

    $groupType = (string) @$_POST['groupType'];
    unset($_POST['groupType']);

    $buttons = (isset($_POST['labels']) ? $_POST['labels'] : []);

    $query = buildQuery($query, "location");
    $query = buildQuery($query, "neutrals", null, in_array("either-or", $buttons));
    $query = buildQuery($query, "attackers", false, in_array("attackers-or", $buttons));
    $query = buildQuery($query, "victims", true, in_array("victims-or", $buttons));

    $query = parseDate($query, 'start');
    $query = parseDate($query, 'end');
    $startTime = (int) @$query['start'];
    $endTime = (int) @$query['end'];
    if ($startTime > time()) $startTime = time();
    if ($endTime == 0 || $endTime > time()) $endTime = time();
    unset($query['start']);
    unset($query['end']);

    $labels = [];
    foreach ($buttons as $label) {
        $group = getLabelGroup($label);
        if ($group != null) {
            if (!(isset($labels[$group]))) $labels[$group] = [];
            $labels[$group][] = $label;
        }
    }
    foreach ($labels as $group => $search) $query[] = ['labels' => ['$in' => $search]];

    $page = (isset($_POST['radios']['page']) ? max(1, min(10, (int) @$_POST['radios']['page'])) - 1 : 0);
    $sortKey = (isset($validSortBy[$_POST['radios']['sort']['sortBy']]) ? $validSortBy[$_POST['radios']['sort']['sortBy']] : 'killID' );
    $sortBy = (isset($validSortDir[$_POST['radios']['sort']['sortDir']]) ? $validSortDir[$_POST['radios']['sort']['sortDir']] : -1 );
    $sort = [$sortKey => $sortBy];

    $groupAggType = (string) @$_POST['radios']['group-agg-type'];
    $victimsOnly = ($groupAggType == "victims only" ? true : ($groupAggType == "attackers only" ? false : null));
    unset($_POST['radios']['group-agg-type']);

    $coll = ['killmails'];
    if ($sortKey == 'killID' && $sortBy == -1 && @$query['hasDateFilter'] != true) {
        $coll = ['oneWeek', 'ninetyDays', 'killmails'];
    }
    unset($query['hasDateFilter']);

    if (sizeof($query) == 0) $query = [];
    else if (sizeof($query) == 1) $query = $query[0];
    else $query = ['$and' => $query];

    // CORS headers
    //header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET,POST');

    // Should prevent cache busting from url manipulation
    array_multisort($query);

    $arr = [];
    if ($queryType == "kills") {
        $app->contentType('application/json; charset=utf-8');
        foreach ($coll as $col) {
            $result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(100 * $page)->limit(100));
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
        $arr = getSums($groupType . 'ID', $query, $victimsOnly);

        if (isset($arr['isk'])) $arr['isk'] = Util::formatIsk($arr['isk']);
        if (isset($arr['kills'])) $arr['kills'] = number_format($arr['kills'], 0);
        unset($arr['_id']);
    } else if ($queryType == "groups") {
        $app->contentType('text/html; charset=utf-8');
        $arr['top'] = [];
        if (in_array($groupType, $types)) {
            $res = getTop($groupType . 'ID', $query, $victimsOnly);
            $app->render("components/asearch_top_list.html", ['topSet' => ['type' => $groupType, 'title' => 'Top ' . Util::pluralize(ucwords($groupType)), 'values' => $res]]);
        }
        return;
    } else {
        // what is this? ignore it...
        $arr = [];
        $app->contentType('application/json; charset=utf-8');
    }

    echo json_encode($arr, true);

} catch (Exception $ex) {
    Log::log(print_r($ex, true));
}

function buildQuery($queries, $key, $isVictim = null, $useOrJoin = false) {
    $query = buildFromArray($key, $isVictim, $useOrJoin);
    if ($query != null && sizeof($query) > 0) $queries[] = $query;
    return $queries;
}


function buildFromArray($key, $isVictim = null, $useOrJoin = false) {
    if (!isset($_POST[$key])) return null;

    $arr = $_POST[$key];
    $params = [];
    foreach ($arr as $row) {
        $param = [];
        if ($row['type'] == 'systemID') $row['type'] = 'solarSystemID';
        if ($row['type'] == 'shipID') $row['type'] = 'shipTypeID';

        $param[$row['type']] = (int) $row['id'];
        if ($isVictim === false) $param['kills'] = true;
        else if ($isVictim === true) $param['losses'] = true;
        $params[] = MongoFilter::buildQuery($param, true);
    }
    return [("$" . ($useOrJoin ? 'or' : 'and')) => $params];
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


function getTop($groupByColumn, $query, $victimsOnly, $cacheOverride = false, $addInfo = true)
{
    global $mdb, $longQueryMS, $redis;

    $hashKey = "Stats::getTop:q:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
    while ($redis->get("inprogress:$hashKey") == "true") sleep(1);
    try {
        $result = RedisCache::get($hashKey);
        if ($cacheOverride == false && $result != null) {
            return $result;
        }
        $redis->setex("inprogress:$hashKey", 60, "true");

        $killmails = $mdb->getCollection('killmails');

        if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
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
        if ($victimsOnly !== null) $pipeline[] = ['$match' => ['involved.isVictim' => (bool) $victimsOnly]];
        $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
        //$pipeline[] = ['$match' => $andQuery];
        $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$'.$keyField]]];
        $pipeline[] = ['$group' => ['_id' => '$_id.'.$groupByColumn, 'kills' => ['$sum' => 1]]];
        $pipeline[] = ['$sort' => ['kills' => -1]];
        $pipeline[] = ['$limit' => 100];
        $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

        $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
        $result = $rr['result'];

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            global $uri;
            Log::log("getTop Long query (${time}ms): $hashKey $uri");
        }

        if ($addInfo) Info::addInfo($result);
        RedisCache::set($hashKey, $result, 900);

        return $result;
    } catch (Exception $ex) {
        RedisCache::set($hashKey, [], 900);
    } finally {
        $redis->del("inprogress:$hashKey");
    }
}

function getSums($groupByColumn, $query, $victimsOnly, $cacheOverride = false, $addInfo = true)
{
    global $mdb, $longQueryMS;

    $hashKey = "Stats::getSums:q:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
    try {
        $result = RedisCache::get($hashKey);
        if ($cacheOverride == false && $result != null) {
            return $result;
        }

        $killmails = $mdb->getCollection('killmails');

        if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
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
        if ($victimsOnly !== null) $pipeline[] = ['$match' => ['involved.isVictim' => (bool) $victimsOnly]];
        $pipeline[] = ['$group' => ['_id' => 0, 'isk' => ['$sum' => '$zkb.totalValue'], 'kills' => ['$sum' => 1]]];

        $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
        $result = $rr['result'][0];

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            global $uri;
            Log::log("getTop Long query (${time}ms): $hashKey $uri");
        }

        RedisCache::set($hashKey, $result, 900);

        return $result;


    } catch (Exception $ex) {
        RedisCache::set($hashKey, [], 900);
    }
}
