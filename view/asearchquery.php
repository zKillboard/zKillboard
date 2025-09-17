<?php

use cvweiss\redistools\RedisCache;

global $mdb, $redis, $uri;

/*if (@$_SESSION['characterID'] <= 0) {
    header("HTTP/1.1 403 Must be logged in to use this feature.");
    return;
}*/

MongoCursor::$timeout = 65000;

/*if ($redis->get("zkb_reinforced") == true || $redis->get("zkb:load") > 14) $redis->setex("zkb_reinforced_as_extended", 300, "true");
if ($redis->get("zkb:reinforced") == true || $redis->get("zkb_reinforced_as_extended") == "true") {
    header('HTTP/1.1 403 Reinforced mode, please try again later'); 
    return;
}*/

$labelGroupMaps = [
	'cat' => 'Categories',
	'isk' => 'ISK Ranges',
	'loc' => 'Region Types',
	'tz' => 'Timezones'
];

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

	$validSortBy = ['date' => 'killID', 'isk' => 'zkb.totalValue', 'involved' => 'attackerCount', 'damage' => 'damage_taken'];
	$validSortDir = ['asc' => 1, 'desc' => -1];

	$_POST = $_GET;
	$query = [];

	$queryType = (string) @$_POST['queryType'];
	if ($queryType == "") $queryType = "kills";
	unset($_POST['queryType']);

	$groupType = (string) @$_POST['groupType'];
	unset($_POST['groupType']);

	$buttons = (isset($_POST['labels']) ? $_POST['labels'] : []);

	$query = buildQuery($query, "neutrals", null, getSelectedFromBase('either', $buttons), true);
	$query = buildQuery($query, "attackers", false, getSelectedFromBase('attackers-', $buttons), true);
	$query = buildQuery($query, "victims", true, getSelectedFromBase('victims-', $buttons), true);

	$filter = [];
	if (@$_POST['includeAssociates'] !== "true") {
		$filter = buildQuery($filter, "neutrals", null, getSelectedFromBase('either', $buttons), false);
		$filter = buildQuery($filter, "attackers", false, getSelectedFromBase('attackers-', $buttons), false);
		$filter = buildQuery($filter, "victims", true, getSelectedFromBase('victims-', $buttons), false);
		if (sizeof($filter) == 0) $filter = [];
		else if (sizeof($filter) == 1) $filter = $filter[0];
		else $filter = ['$and' => $filter];
	}
	unset($_POST['includeAssociates']);

	$query = buildQuery($query, "location");
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
	$sortKey = (isset($validSortBy[$_POST['radios']['sort']['sortBy']]) ? $validSortBy[$_POST['radios']['sort']['sortBy']] : 'killID');
	$sortBy = (isset($validSortDir[$_POST['radios']['sort']['sortDir']]) ? $validSortDir[$_POST['radios']['sort']['sortDir']] : -1);
	$sort = [$sortKey => $sortBy];

	$groupAggType = (string) @$_POST['radios']['group-agg-type'];
	$victimsOnly = ($groupAggType == "victims only" ? "true" : ($groupAggType == "attackers only" ? "false" : "null"));
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
	header('Access-Control-Allow-Origin: https://zkillboard.com');
	header('Access-Control-Allow-Methods: GET,POST');

	// Should prevent cache busting from url manipulation
	array_multisort($query);
	$jsoned = json_encode($query, true) . json_encode($filter, true);
	$key = "asearch:$queryType:$groupType:$victimsOnly:" . ($queryType == "kills" ? "$page:$sortKey:$sortBy:" : "") . md5($jsoned);

	$waits = 0;
	do {
		$ret = (string) $redis->get($key);
		if ($ret == "PROCESSING") {
			sleep(1);
			$waits++;
			if ($waits > 25) {
				//header("Location: $uri");
				header('HTTP/1.1 408 Request timeout');
				return;
			}
		}
	} while ($ret == "PROCESSING");

	if ($ret != "") {
		$app->contentType('application/json; charset=utf-8');
		echo $ret;
		return;
	}
	$redis->setex($key, 300, "PROCESSING");

	$arr = [];
	if ($queryType == "kills") {
		$app->contentType('application/json; charset=utf-8');
		foreach ($coll as $col) {
			$result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(100 * $page)->limit(100));
			if (sizeof($result) >= 100) break;
		}
		$arr['kills'] = [];
		foreach ($result as $row) {
			$killID = $row['killID'];
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
		$rendered = $redis->get("groups:$key");
		if ($rendered !== null && trim($rendered) !== "") {
			$redis->del($key);
			echo $rendered;
			return;
		}
		$arr['top'] = [];
		ob_start();
		if (in_array($groupType, $types)) {
			$res = getTop($groupType . 'ID', $query, $victimsOnly, $filter, true, $sortKey, $sortBy);
			$app->render("components/asearch_top_list.html", ['topSet' =>
			[
				'type' => $groupType,
				'singularTitle' => ucwords($groupType),
				'title' => 'Top ' . Util::pluralize(ucwords($groupType)),
				'values' => $res,
				'sortKey' => $sortKey,
				'sortBy' => $sortBy
			]]);
		}
		$rendered = ob_get_clean();
		echo $rendered;
		$redis->setex("groups:$key", 300, trim($rendered));
		$redis->del($key);
		return;
	} else if ($queryType == "labels") {
		$app->contentType('text/html; charset=utf-8');
		$arr['top'] = [];

		$rendered = $redis->get("labels:$key");
		if ($rendered !== null && trim($rendered) !== "") {
			$redis->del($key);
			echo $rendered;
			return;
		}

		$res = getLabels($query, $victimsOnly);
		ob_start();
		if ($res == null) $res = [];
		foreach ($res as $labelGroup) {
			if ($labelGroup['_id'] == "cat") {
				for ($i = 0; $i < sizeof($labelGroup['rights']); $i++) {
					$labelGroup['rights'][$i]['right'] = Util::pluralize(Info::getInfoField('categoryID', (int) $labelGroup['rights'][$i]['right'], 'name'));
				}
			}
			if (isset($labelGroupMaps[$labelGroup['_id']])) $labelGroup['_id'] = $labelGroupMaps[$labelGroup['_id']];
			$app->render("components/asearch_top_list.html", ['topSet' =>
			[
				'type' => $labelGroup['_id'],
				'singularTitle' => ucwords($labelGroup['_id']),
				'title' => 'Top ' . ucwords($labelGroup['_id']),
				'values' => $labelGroup['rights'],
				'sortKey' => 'count',
				'sortBy' => $sortBy
			]]);
		}
		$rendered = ob_get_clean();
		echo $rendered;
		$redis->setex("labels:$key", 900, trim($rendered));
		$redis->del($key);
		return;
	} else if ($queryType == "distincts") {
		$app->contentType('text/html; charset=utf-8');

		$rendered = $redis->get("distincts:$key");
		if ($rendered !== null && trim($rendered) !== "") {
			$redis->del($key);
			echo $rendered;
			return;
		}

		$result = getDistincts($query, $filter, $victimsOnly);

		$res = [];
		foreach ($result as $type => $count) {
			$res[] = ['type' => ucwords(str_replace("IDs", "s", $type)), 'count' => $count];
		}
		ob_start();
		$app->render("components/asearch_distincts.html", ['result' => $res]);
		$rendered = ob_get_clean();
		echo $rendered;
		$redis->setex("distincts:$key", 900, trim($rendered));
		$redis->del($key);
		return;
	} else {
		// what is this? ignore it...
		$arr = [];
		$app->contentType('application/json; charset=utf-8');
	}

	$jsoned = json_encode($arr, true);
	$redis->setex($key, 300, $jsoned);
	echo $jsoned;
} catch (Exception $ex) {
	Util::zout(print_r($ex, true));
}

function buildQuery($queries, $key, $isVictim = null, $joinType = 'and', $useElemMatch = true)
{
	$query = buildFromArray($key, $isVictim, $joinType, $useElemMatch);
	if ($query != null && sizeof($query) > 0) $queries[] = $query;
	return $queries;
}


function buildFromArray($key, $isVictim = null, $joinType = 'and', $useElemMatch)
{
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
		$params[] = MongoFilter::buildQuery($param, $useElemMatch);
	}

	if ($joinType == 'or' || $joinType == '-or') return ['$or' => $params];
	if ($joinType == 'and' || $joinType == "-and") return ['$and' => $params];

	// Last option is 'mergedand' or 'In' on the site, we need to merge everything
	$merged = [];
	foreach ($params as $param) {
		$merged = array_merge_recursive($merged, $param);
	}
	if (isset($merged['involved']['$elemMatch']['isVictim'])) $merged['involved']['$elemMatch']['isVictim'] = $isVictim;
	return $merged;
}

function getLabelGroup($label)
{
	foreach (AdvancedSearch::$labels as $group => $labels) {
		if (in_array($label, array_keys($labels))) return $group;
	}
	return null;
}

function parseDate($query, $which)
{
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

function getTop($groupByColumn, $query, $victimsOnly, $filter, $addInfo, $sortKey, $sortBy)
{
	global $mdb, $longQueryMS, $redis;

	$hashKey = "Stats::getTop:q:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
	while ($redis->get("inprogress:$hashKey") == "true") sleep(1);
	try {
		$redis->setex("inprogress:$hashKey", 60, "true");

		$killmails = $mdb->getCollection('killmails');

		if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
			$keyField = "system.$groupByColumn";
		} elseif ($groupByColumn != 'locationID') {
			$keyField = "involved.$groupByColumn";
		} else {
			$keyField = $groupByColumn;
		}

		$timer = new Timer();
		$pipeline = [];
		$pipeline[] = ['$match' => $query];
		if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
			$pipeline[] = ['$unwind' => '$involved'];
			if (sizeof($filter)) $pipeline[] = ['$match' => $filter];
		}
		if ($victimsOnly != "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
		$pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
		$pipeline[] = ['$match' => [$keyField => ['$ne' => 0]]];
		$pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$' . $keyField, 'totalValue' => '$zkb.totalValue', 'involved' => '$attackerCount', 'damage' => '$damage_taken']]];

		if ($sortKey == "damage_taken") {
			$pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => '$_id.damage']]];
		} else if ($sortKey == "attackerCount") {
			$pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$avg' => '$_id.involved']]];
		} else if ($sortKey == "zkb.totalValue") {
			$pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => '$_id.totalValue']]];
		} else {
			$pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => 1]]];
		}
		$pipeline[] = ['$sort' => ['kills' => $sortBy]];
		$pipeline[] = ['$limit' => 150];
		$pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

		$rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
		$result = $rr['result'];

		$time = $timer->stop();
		if ($time > $longQueryMS) {
			global $uri;
			Util::zout("getTop Long query (${time}ms): $hashKey $uri");
		}

		$result = Util::removeDQed($result, $groupByColumn, 100);

		if ($addInfo) Info::addInfo($result);

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
		if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
		$pipeline[] = ['$group' => ['_id' => 0, 'isk' => ['$sum' => '$zkb.totalValue'], 'kills' => ['$sum' => 1]]];

		$rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
		$result = $rr['result'][0];

		$time = $timer->stop();
		if ($time > $longQueryMS) {
			global $uri;
			Util::zout("getTop Long query (${time}ms): $hashKey $uri");
		}

		RedisCache::set($hashKey, $result, 900);

		return $result;
	} catch (Exception $ex) {
		RedisCache::set($hashKey, [], 900);
	}
}

function getLabels($query, $victimsOnly)
{
	global $mdb, $longQueryMS;

	try {
		$killmails = $mdb->getCollection('killmails');

		$timer = new Timer();
		$pipeline = [];
		$pipeline[] = ['$match' => $query];
		if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];

		$pipeline[] = ['$unwind' => '$labels'];
		$pipeline[] = ['$project' => ['split' => ['$split' => ['$labels', ':']]]];
		$pipeline[] = [
			'$project' => [
				'left' => [
					'$switch' => [
						'branches' => [
							[
								'case' => [
									'$eq' => [
										['$arrayElemAt' => ['$split', 0]],
										'solo'
									]
								],
								'then' => 'involved'
							],
							[
								'case' => [
									'$eq' => [
										['$size' => '$split'],
										1
									]
								],
								'then' => 'other'
							]
						],
						'default' => [
							'$cond' => [
								[
									'$eq' => [
										['$arrayElemAt' => ['$split', 0]],
										'#'
									]
								],
								'involved',
								['$arrayElemAt' => ['$split', 0]]
							]
						]
					]
				],
				'right' => [
					'$switch' => [
						'branches' => [
							[
								'case' => [
									'$eq' => [
										['$arrayElemAt' => ['$split', 0]],
										'solo'
									]
								],
								'then' => 'solo'
							],
							[
								'case' => [
									'$eq' => [
										['$size' => '$split'],
										1
									]
								],
								'then' => ['$arrayElemAt' => ['$split', 0]]
							]
						],
						'default' => ['$arrayElemAt' => ['$split', 1]]
					]
				]
			]
		];

		$pipeline[] = [
			'$group' => [
				'_id' => [
					'left' => '$left',
					'right' => '$right'
				],
				'count' => ['$sum' => 1]
			]
		];

		$pipeline[] = [
			'$sort' => [
				'_id.left' => 1,
				'count' => -1,
				'_id.right' => 1
			]
		];

		$pipeline[] = [
			'$group' => [
				'_id' => '$_id.left',
				'rights' => [
					'$push' => [
						'right' => '$_id.right',
						'count' => '$count'
					]
				]
			]
		];

		$pipeline[] = [
			'$sort' => [
				'_id' => 1
			]
		];

		$rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
		$result = $rr['result'];

		$time = $timer->stop();
		if ($time > $longQueryMS) {
			global $uri;
			Util::zout("getTop Long query (${time}ms): $uri");
		}
		return $result;
	} catch (Exception $ex) {
		if ($ex->getCode() != 50) Util::zout(print_r($ex, true)); // code 50 is query timeout
	}
}

function getDistincts($query, $filter, $victimsOnly)
{
	global $mdb, $longQueryMS;

	$hashKey = "Stats::getDistincts:" . serialize($query) . ":" . serialize($victimsOnly);
	try {
		$killmails = $mdb->getCollection('killmails');

		$timer = new Timer();
		$pipeline = [];
		$pipeline[] = ['$match' => $query];
		if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];

		$pipeline[] = [
			'$unwind' => '$involved'
		];
		if (sizeof($filter)) $pipeline[] = ['$match' => $filter];

		$pipeline[] = [
			'$group' => [
				'_id' => null,
				'characterIDs'    => ['$addToSet' => '$involved.characterID'],
				'corporationIDs'  => ['$addToSet' => '$involved.corporationID'],
				'allianceIDs'     => ['$addToSet' => '$involved.allianceID'],
				'factionIDs'      => ['$addToSet' => '$involved.factionID'],
				'shipTypeIDs'     => ['$addToSet' => '$involved.shipTypeID'],
				'groupIDs'        => ['$addToSet' => '$involved.groupID'],
				'solarSystemIDs'  => ['$addToSet' => '$system.solarSystemID'],
				'constellationIDs' => ['$addToSet' => '$system.constellationID'],
				'regionIDs'       => ['$addToSet' => '$system.regionID']
			]
		];

		$pipeline[] = [
			'$project' => [
				'_id' => 0,
				'characterIDs' => ['$size' => ['$filter' => ['input' => '$characterIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'corporationIDs' => ['$size' => ['$filter' => ['input' => '$corporationIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'allianceIDs' => ['$size' => ['$filter' => ['input' => '$allianceIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'factionIDs' => ['$size' => ['$filter' => ['input' => '$factionIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'shipTypeIDs' => ['$size' => ['$filter' => ['input' => '$shipTypeIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'groupIDs' => ['$size' => ['$filter' => ['input' => '$groupIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'solarSystemIDs' => ['$size' => ['$filter' => ['input' => '$solarSystemIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'constellationIDs' => ['$size' => ['$filter' => ['input' => '$constellationIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
				'regionIDs' => ['$size' => ['$filter' => ['input' => '$regionIDs', 'cond' => ['$ne' => ['$$this', null]]]]]
			]
		];

		$rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
		$result = isset($rr['result'][0]) ? $rr['result'][0] : [];

		$time = $timer->stop();
		if ($time > $longQueryMS) {
			global $uri;
			Util::zout("getTop Long query (${time}ms): $uri");
		}

		return $result;
	} catch (Exception $ex) {
		if ($ex->getCode() != 50) Util::zout(print_r($ex, true));
		return [];
	}
}

function getSelectedFromBase($base, $buttons)
{
	foreach ($buttons as $button) {
		if (Util::startsWith($button, $base)) return str_replace($base, '', $button);
	}
	return 'and'; // default
}
