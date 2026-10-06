<?php

function handler($request, $response, $args, $container)
{
	global $mdb, $redis, $uri;

	try {
		$params = URI::validate($uri, ['u' => true]);
	} catch (Exception $e) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,activity'), 'components/activity_async.pug', []);
	}

	$split = explode('/', trim($params['u'], '/'));
	$id = $split[1] ?? 0;
	if (!is_numeric($id) || $id <= 0) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,activity'), 'components/activity_async.pug', []);
	}

	$activity = ['max' => 0];
	$raw = $redis->hget('zkb:activity', $id);
	if ($raw != null) {
		$activity = unserialize($raw);
	} else {
		$rows = $mdb->getCollection('activity')->aggregate([
			['$match' => ['id' => (int) $id]],
			['$group' => ['_id' => ['day' => '$day', 'hour' => '$hour'], 'count' => ['$sum' => 1]]],
		]);
		foreach ($rows as $row) {
			$day = (int) $row['_id']['day'];
			$hour = (int) $row['_id']['hour'];
			$count = (int) $row['count'];
			$activity[$day][$hour] = $count;
			$activity['max'] = max($activity['max'], $count);
		}
	}

	return $container->get('view')->render($response->withHeader('Cache-Tag', "www,activity,overview:$id"), 'components/activity_async.pug', ['activity' => $activity]);
}
