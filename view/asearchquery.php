<?php

use cvweiss\redistools\RedisCache;

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

	MongoCursor::$timeout = 65000;
	$key = "asearch:defaultKey"; // placeholder to avoid undefined variable error

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

		$validSortBy = ['date' => 'killID', 'isk' => 'zkb.totalValue', 'involved' => 'attackerCount', 'damage' => 'damage_taken', 'points' => 'zkb.points'];
		$validSortDir = ['asc' => 1, 'desc' => -1];

		$query = [];

		$queryParams = $request->getQueryParams();
		$queryType = (string) @$queryParams['queryType'];
		if ($queryType == "") $queryType = "kills";
		unset($queryParams['queryType']);

		$groupType = (string) @$queryParams['groupType'];
		unset($queryParams['groupType']);

		$buttons = (isset($queryParams['labels']) ? $queryParams['labels'] : []);

		$query = AdvancedSearch::buildQuery($queryParams, $query, "neutrals", null, AdvancedSearch::getSelectedFromBase('either', $buttons), true);
		$query = AdvancedSearch::buildQuery($queryParams, $query, "attackers", false, AdvancedSearch::getSelectedFromBase('attackers-', $buttons), true);
		$query = AdvancedSearch::buildQuery($queryParams, $query, "victims", true, AdvancedSearch::getSelectedFromBase('victims-', $buttons), true);

		$filter = [];
		if (@$queryParams['includeAssociates'] !== "true") {
			$filter = AdvancedSearch::buildQuery($queryParams, $filter, "neutrals", null, AdvancedSearch::getSelectedFromBase('either', $buttons), false);
			$filter = AdvancedSearch::buildQuery($queryParams, $filter, "attackers", false, AdvancedSearch::getSelectedFromBase('attackers-', $buttons), false);
			$filter = AdvancedSearch::buildQuery($queryParams, $filter, "victims", true, AdvancedSearch::getSelectedFromBase('victims-', $buttons), false);
			if (sizeof($filter) == 0) $filter = [];
			else if (sizeof($filter) == 1) $filter = $filter[0];
			else $filter = ['$and' => $filter];
		}
		unset($queryParams['includeAssociates']);

		$query = AdvancedSearch::buildQuery($queryParams, $query, "location");
		$query = AdvancedSearch::parseDate($queryParams, $query, 'start');
		$query = AdvancedSearch::parseDate($queryParams, $query, 'end');
		$startTime = (int) @$query['start'];
		$endTime = (int) @$query['end'];
		if ($startTime > time()) $startTime = time();
		if ($endTime == 0 || $endTime > time()) $endTime = time();
		unset($query['start']);
		unset($query['end']);

		$labels = [];
		foreach ($buttons as $label) {
			$group = AdvancedSearch::getLabelGroup($label);
			if ($group != null) {
				if (!(isset($labels[$group]))) $labels[$group] = [];
				$labels[$group][] = $label;
			}
		}
		foreach ($labels as $group => $search) $query[] = ['labels' => ['$in' => $search]];

		$page = (isset($queryParams['radios']['page']) ? max(1, min(10, (int) @$queryParams['radios']['page'])) - 1 : 0);
		$sortKey = (isset($validSortBy[$queryParams['radios']['sort']['sortBy']]) ? $validSortBy[$queryParams['radios']['sort']['sortBy']] : 'killID');
		$sortBy = (isset($validSortDir[$queryParams['radios']['sort']['sortDir']]) ? $validSortDir[$queryParams['radios']['sort']['sortDir']] : -1);
		$sort = [$sortKey => $sortBy];

		$groupAggType = (string) @$queryParams['radios']['group-agg-type'];
		$victimsOnly = ($groupAggType == "victims only" ? "true" : ($groupAggType == "attackers only" ? "false" : "null"));
		unset($queryParams['radios']['group-agg-type']);

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
		$ret = "";
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

		if (false && $ret != "") {
			$response->getBody()->write($ret);
			return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
		}
		$redis->setex($key, 300, "PROCESSING");

		$arr = [];
		if ($queryType == "kills") {
			foreach ($coll as $col) {
				$result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(100 * $page)->limit(100));
				if (sizeof($result) >= 100) break;
			}
			$arr['kills'] = [];
			foreach ($result as $row) {
				$killID = $row['killID'];
				$arr['kills'][] = $killID;
			}			
			$jsoned = json_encode($arr, true);
			$redis->setex($key, 300, $jsoned);
			$response->getBody()->write($jsoned);
			return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
		} else if ($queryType == 'count') {
			foreach ($coll as $col) {
				$result = iterator_to_array($mdb->getCollection($col)->find($query)->sort($sort)->skip(50 * $page)->limit(50));
				if (sizeof($result) >= 50) break;
			}
			$arr = AdvancedSearch::getSums($groupType . 'ID', $query, $victimsOnly);
			unset($arr['_id']);
			$jsoned = json_encode($arr, true);
			$redis->setex($key, 300, $jsoned);
			$response->getBody()->write($jsoned);
			return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
		} else if ($queryType == "groups") {
			$rendered = $redis->get("groups:$key");
			if ($rendered !== null && trim($rendered) !== "") {
				$redis->del($key);
				$response->getBody()->write($rendered);
				return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
			}
			$arr['top'] = [];
			$rendered = '';
			if (in_array($groupType, $types)) {
				$res = AdvancedSearch::getTop($groupType . 'ID', $query, $victimsOnly, $filter, true, $sortKey, $sortBy);
				$rendered = $container['view']->getEnvironment()->render("components/asearch_top_list.html", ['topSet' =>
				[
					'type' => $groupType,
					'singularTitle' => ucwords($groupType),
					'title' => 'Top ' . Util::pluralize(ucwords($groupType)),
					'values' => $res,
					'sortKey' => $sortKey,
					'sortBy' => $sortBy
				]]);
			}
			$redis->setex("groups:$key", 300, trim($rendered));
			$redis->del($key);
			$response->getBody()->write($rendered);
			return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		} else if ($queryType == "labels") {
			$arr['top'] = [];

			$rendered = $redis->get("labels:$key");
			if ($rendered !== null && trim($rendered) !== "") {
				$redis->del($key);
				$response->getBody()->write($rendered);
				return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
			}

			$res = AdvancedSearch::getLabels($query, $victimsOnly);
			$rendered = '';
			if ($res == null) $res = [];
			foreach ($res as $labelGroup) {
				if ($labelGroup['_id'] == "cat") {
					for ($i = 0; $i < sizeof($labelGroup['rights']); $i++) {
						$labelGroup['rights'][$i]['right'] = Util::pluralize(Info::getInfoField('categoryID', (int) $labelGroup['rights'][$i]['right'], 'name'));
					}
				}
				if (isset($labelGroupMaps[$labelGroup['_id']])) $labelGroup['_id'] = $labelGroupMaps[$labelGroup['_id']];
				$rendered .= $container['view']->getEnvironment()->render("components/asearch_top_list.html", ['topSet' =>
				[
					'type' => $labelGroup['_id'],
					'singularTitle' => ucwords($labelGroup['_id']),
					'title' => 'Top ' . ucwords($labelGroup['_id']),
					'values' => $labelGroup['rights'],
					'sortKey' => 'count',
					'sortBy' => $sortBy
				]]);
			}
			$redis->setex("labels:$key", 900, trim($rendered));
			$redis->del($key);
			$response->getBody()->write($rendered);
			return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		} else if ($queryType == "distincts") {
			$rendered = $redis->get("distincts:$key");
			if ($rendered !== null && trim($rendered) !== "") {
				$redis->del($key);
				$response->getBody()->write($rendered);
				return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
			}

			$result = AdvancedSearch::getDistincts($query, $filter, $victimsOnly);

			$res = [];
			foreach ($result as $type => $count) {
				$res[] = ['type' => ucwords(str_replace("IDs", "s", $type)), 'count' => $count];
			}
			$rendered = $container['view']->getEnvironment()->render("components/asearch_distincts.html", ['result' => $res]);
			$redis->setex("distincts:$key", 900, trim($rendered));
			$redis->del($key);
			$response->getBody()->write($rendered);
			return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
		} else {
			// what is this? ignore it...
			$arr = [];
		}

		$jsoned = json_encode($arr, true);
		$redis->setex($key, 300, $jsoned);
		$response->getBody()->write($jsoned);
		return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
	} catch (Exception $ex) {
		Util::zout(print_r($ex, true));
	} 
}