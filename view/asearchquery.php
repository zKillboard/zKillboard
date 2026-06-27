<?php

use cvweiss\redistools\RedisCache;

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $uri;

	$key = "asearch:defaultKey"; // placeholder to avoid undefined variable error
	$queryType = "unknown";
	$groupType = "";
	$victimsOnly = "null";
	$queryParams = [];
	$query = [];
	$filter = [];
	$sort = [];
	$sortKey = "";
	$sortBy = "";
	$page = 0;
	$coll = [];
	$aggregateCollection = "";

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

		$epochButton = (string) @$queryParams['epochbtn'];
		$usePeriodCollectionOnly = in_array($epochButton, ['week', 'recent'], true);

		$query = AdvancedSearch::buildQuery($queryParams, $query, "location", null, 'or');
		if (!$usePeriodCollectionOnly) {
			$query = AdvancedSearch::parseDate($queryParams, $query, 'start');
			$query = AdvancedSearch::parseDate($queryParams, $query, 'end');
		}

		$startTime = (int) @$query['start'];
		$endTime = (int) @$query['end'];
		$now = time();
		if ($startTime > $now) $startTime = $now;
		if ($endTime == 0 || $endTime > $now) $endTime = $now;
		$guaranteedQuery = in_array($epochButton, ['week', 'recent'], true) || ($startTime > 0 && ($endTime - $startTime) <= 7776000);

		$labels = [];
		foreach ($buttons as $label) {
			$group = AdvancedSearch::getLabelGroup($label);
			if ($group != null) {
				if (!(isset($labels[$group]))) $labels[$group] = [];
				$labels[$group][] = $label;
			}
		}
		foreach ($labels as $group => $search) $query[] = ['labels' => ['$in' => $search]];
		if (!$guaranteedQuery) {
			$query = AdvancedSearch::buildItemHistoryQuery($queryParams, $query, "items", AdvancedSearch::getSelectedFromBase('items-', $buttons), 5000);
		}
		unset($query['start']);
		unset($query['end']);

		$page = (isset($queryParams['radios']['page']) ? max(1, min(10, (int) @$queryParams['radios']['page'])) - 1 : 0);
		$sortKey = (isset($queryParams['radios']['sort']['sortBy']) && isset($validSortBy[$queryParams['radios']['sort']['sortBy']]) ? $validSortBy[$queryParams['radios']['sort']['sortBy']] : 'killID');
		$sortBy = (isset($queryParams['radios']['sort']['sortDir']) && isset($validSortDir[$queryParams['radios']['sort']['sortDir']]) ? $validSortDir[$queryParams['radios']['sort']['sortDir']] : -1);
		$sort = [$sortKey => $sortBy];

		$groupAggType = (string) ($queryParams['radios']['group-agg-type'] ?? '');
		$victimsOnly = ($groupAggType == "victims only" ? "true" : ($groupAggType == "attackers only" ? "false" : "null"));
		if (isset($queryParams['radios']['group-agg-type'])) {
			unset($queryParams['radios']['group-agg-type']);
		}

		if ($epochButton == 'week') {
			$coll = ['oneWeek'];
		} else if ($epochButton == 'recent') {
			$coll = ['ninetyDays'];
		} else if ($sortKey == 'killID' && $sortBy == -1 && @$query['hasDateFilter'] != true) {
			$coll = ['oneWeek', 'ninetyDays', 'killmails'];
		} else {
			$coll = ['killmails'];
		}
		$aggregateCollection = getAsearchAggregateCollection($startTime, $now, $epochButton);
		$cacheTime = getAsearchCacheTime($startTime, $endTime, $epochButton, $queryType == "kills" ? $coll : [$aggregateCollection]);
		unset($query['hasDateFilter']);

		if (sizeof($query) == 0) $query = [];
		else if (sizeof($query) == 1) $query = $query[0];
		else $query = ['$and' => $query];

		// CORS headers
		header('Access-Control-Allow-Origin: https://zkillboard.com');
		header('Access-Control-Allow-Methods: GET,POST');

		// Should prevent cache busting from url manipulation
		array_multisort($query);
		$jsoned = json_encode($query, true) . json_encode($filter, true) . json_encode(@$queryParams['items'], true) . AdvancedSearch::getSelectedFromBase('items-', $buttons);
		$collectionScope = ($queryType == "kills" ? implode(',', $coll) : $aggregateCollection);
		$key = "asearch:$queryType:$groupType:$victimsOnly:$collectionScope:" . ($queryType == "kills" ? "$page:$sortKey:$sortBy:" : "") . md5($jsoned);
		$cacheTag = "www,asearch,asearch:$key";
		$job = [
			'key' => $key,
			'queryType' => $queryType,
			'groupType' => $groupType,
			'victimsOnly' => $victimsOnly,
			'coll' => $coll,
			'aggregateCollection' => $aggregateCollection,
			'page' => $page,
			'sortKey' => $sortKey,
			'sortBy' => $sortBy,
			'sort' => $sort,
			'query' => $query,
			'filter' => $filter,
			'types' => $types,
			'queryParams' => $queryParams,
			'itemJoin' => AdvancedSearch::getSelectedFromBase('items-', $buttons),
			'guaranteedQuery' => $guaranteedQuery,
			'cacheTime' => $cacheTime
		];
		if ($queryType != 'kills' && $queryType != 'count') {
			$rendered = $redis->get("$queryType:$key");
			if ($rendered !== false && $rendered !== null && trim($rendered) !== "") {
				$response->getBody()->write($rendered);
				return withAsearchCacheHeaders($response, $cacheTime)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
			}
		}

		$waits = 0;
		$ret = "";
		do {
			$rawResult = $redis->get("$key:result");
			if ($rawResult !== false && $rawResult !== null) {
				$redis->del("$key:result");
				$redis->del($key);
				return renderAsearchResult($response, $container, $cacheTag, $job, unserialize($rawResult), $labelGroupMaps);
			}
			$ret = (string) $redis->get($key);
			if ($ret == "PROCESSING") {
				usleep(100000); // 100ms
				$waits++;
				if ($guaranteedQuery && $waits > 50) { // 5 seconds
					$isJson = $queryType == 'kills' || $queryType == 'count';
					$response->getBody()->write($isJson ? json_encode(['processing' => true]) : '');
					return $response
						->withHeader('Content-Type', $isJson ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8')
						->withHeader('Cache-Control', 'no-store')
						->withHeader('Cache-Tag', $cacheTag)
						->withHeader('Retry-After', '3')
						->withStatus(202);
				}
				if (!$guaranteedQuery && $waits > 250) { // 25 seconds
					AdvancedSearch::logTimeout('asearch processing wait', [
						'cacheKey' => $key,
						'queryType' => $queryType,
						'groupType' => $groupType,
						'victimsOnly' => $victimsOnly,
						'collections' => $coll,
						'aggregateCollection' => $aggregateCollection,
						'page' => $page,
						'sortKey' => $sortKey,
						'sortBy' => $sortBy,
						'sort' => $sort,
						'query' => $query,
						'filter' => $filter,
						'requestParams' => $queryParams,
						'uri' => $uri
					]);
					$response->getBody()->write(json_encode(['error' => 'Request timeout'], JSON_PRETTY_PRINT));
					return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')->withHeader('Cache-Tag', $cacheTag)->withStatus(408);
				}
			}
		} while ($ret == "PROCESSING");

		if ($ret != "") {
			$response->getBody()->write($ret);
			return withAsearchCacheHeaders($response, $cacheTime)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
		}
		$redis->setex($key, max(300, min($cacheTime, 14400)), "PROCESSING");
		if ($guaranteedQuery) {
			$redis->sadd('queueAsearchSet', $key);
			$redis->setex("$key:params", max(3600, $cacheTime), serialize($job));

			$waits = 0;
			do {
				usleep(100000);
				$rawResult = $redis->get("$key:result");
				if ($rawResult !== false && $rawResult !== null) {
					$redis->del("$key:result");
					$redis->del($key);
					return renderAsearchResult($response, $container, $cacheTag, $job, unserialize($rawResult), $labelGroupMaps);
				}
				$waits++;
			} while ($waits <= 50);

			$isJson = $queryType == 'kills' || $queryType == 'count';
			$response->getBody()->write($isJson ? json_encode(['processing' => true]) : '');
			return $response
				->withHeader('Content-Type', $isJson ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8')
				->withHeader('Cache-Control', 'no-store')
				->withHeader('Cache-Tag', $cacheTag)
				->withHeader('Retry-After', '3')
				->withStatus(202);
		}

		return renderAsearchResult($response, $container, $cacheTag, $job, AdvancedSearch::runQueuedQuery($job), $labelGroupMaps);
	} catch (Exception $ex) {
		if ($ex->getCode() != 50) Util::zout(print_r($ex, true));
		else AdvancedSearch::logTimeout('asearch handler', [
			'cacheKey' => $key,
			'queryType' => $queryType,
			'groupType' => $groupType,
			'victimsOnly' => $victimsOnly,
			'collections' => $coll,
			'aggregateCollection' => $aggregateCollection,
			'page' => $page,
			'sortKey' => $sortKey,
			'sortBy' => $sortBy,
			'sort' => $sort,
			'query' => $query,
			'filter' => $filter,
			'requestParams' => $queryParams,
			'uri' => $uri
		], $ex);
		$redis->del($key);
		$response->getBody()->write(json_encode(['error' => 'Internal server error', 'message' => $ex->getMessage()], JSON_PRETTY_PRINT));
		return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')->withHeader('Cache-Tag', "www,asearch,asearch:$key,error")->withStatus(500);
	} 
}

function renderAsearchResult($response, $container, $cacheTag, $job, $result, $labelGroupMaps)
{
	global $redis;

	$key = $job['key'];
	$cacheTime = (int) ($job['cacheTime'] ?? 300);
	if ($job['queryType'] == 'kills' || $job['queryType'] == 'count') {
		$jsoned = json_encode($result, true);
		$redis->setex($key, $cacheTime, $jsoned);
		$response->getBody()->write($jsoned);
		return withAsearchCacheHeaders($response, $cacheTime)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
	}

	$rendered = '';
	if ($job['queryType'] == 'groups') {
		$rendered = $container->get('view')->getEnvironment()->render("components/asearch_top_list.pug", ['topSet' => [
			'type' => $job['groupType'],
			'singularTitle' => ucwords($job['groupType']),
			'title' => 'Top ' . Util::pluralize(ucwords($job['groupType'])),
			'values' => $result,
			'sortKey' => $job['sortKey'],
			'sortBy' => $job['sortBy']
		]]);
	} else if ($job['queryType'] == 'labels') {
		if ($result == null) $result = [];
		foreach ($result as $labelGroup) {
			if ($labelGroup['_id'] == "cat") {
				for ($i = 0; $i < sizeof($labelGroup['rights']); $i++) {
					$labelGroup['rights'][$i]['right'] = Util::pluralize(Info::getInfoField('categoryID', (int) $labelGroup['rights'][$i]['right'], 'name'));
				}
			}
			if (isset($labelGroupMaps[$labelGroup['_id']])) $labelGroup['_id'] = $labelGroupMaps[$labelGroup['_id']];
			$rendered .= $container->get('view')->getEnvironment()->render("components/asearch_top_list.pug", ['topSet' => [
				'type' => $labelGroup['_id'],
				'singularTitle' => ucwords($labelGroup['_id']),
				'title' => 'Top ' . ucwords($labelGroup['_id']),
				'values' => $labelGroup['rights'],
				'sortKey' => 'count',
				'sortBy' => $job['sortBy']
			]]);
		}
	} else if ($job['queryType'] == 'distincts') {
		$res = [];
		foreach ($result as $type => $count) $res[] = ['type' => ucwords(str_replace("IDs", "s", $type)), 'count' => $count];
		$rendered = $container->get('view')->getEnvironment()->render("components/asearch_distincts.pug", ['result' => $res]);
	}

	$redis->setex($job['queryType'] . ":$key", $cacheTime, trim($rendered));
	$redis->del($key);
	$response->getBody()->write($rendered);
	return withAsearchCacheHeaders($response, $cacheTime)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
}

function iter2array($iter) 
{
    return gettype($iter) == "array" ? $iter : iterator_to_array($iter);
}

function withAsearchCacheHeaders($response, $cacheTime)
{
	$cacheTime = max(0, (int) $cacheTime);
	$cacheControl = "public, max-age=$cacheTime, s-maxage=$cacheTime";
	return $response
		->withHeader('Cache-Control', $cacheControl)
		->withHeader('CDN-Cache-Control', $cacheControl)
		->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
		->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT');
}

function getAsearchCacheTime($startTime, $endTime, $epochButton, $collections)
{
	$span = ($startTime > 0 && $endTime > $startTime) ? $endTime - $startTime : 0;
	if ($span > 0) {
		if ($span <= 604800) return 900;
		if ($span <= 2678400) return 3600;
		if ($span <= 7776000) return 14400;
		return 86400;
	}

	if ($epochButton == 'week') return 900;
	if ($epochButton == 'recent') return 14400;
	if (in_array('killmails', $collections, true)) return 86400;
	if (in_array('oneWeek', $collections, true)) return 900;
	if (in_array('ninetyDays', $collections, true)) return 14400;
	return 86400;
}

function getAsearchAggregateCollection($startTime, $now, $epochButton = '')
{
	$epochButton = trim((string) $epochButton);
	switch ($epochButton) {
		case 'week':
			return 'oneWeek';
		case 'recent':
		case 'current month':
		case 'prior month':
			return 'ninetyDays';
		case 'alltime':
			return 'killmails';
	}

	// Custom and legacy requests do not have a preset button value to trust.
	if ($startTime <= 0) return 'killmails';
	if ($startTime >= ($now - 604800)) return 'oneWeek';
	if ($startTime >= ($now - 7776000)) return 'ninetyDays';
	return 'killmails';
}
