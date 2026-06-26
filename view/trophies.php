<?php

function handler($request, $response, $args, $container)
{
	global $mdb;

	$rows = $mdb->find(
		'trophies',
		[
			'trophies.id' => ['$gt' => 0],
			'trophies.levelCount' => ['$gt' => 0],
		],
		[
			'trophies.levelCount' => -1,
			'trophies.calcTrophies_updated' => -1,
			'trophies.id' => 1,
		],
		100,
		[
			'_id' => 0,
			'trophies.id' => 1,
			'trophies.levelCount' => 1,
			'trophies.maxLevelCount' => 1,
			'trophies.completedPct' => 1,
			'trophies.boxes' => 1,
			'trophies.calcTrophies_updated' => 1,
		]
	);

	$leaders = [];
	foreach ($rows as $row) {
		$trophies = (array) ($row['trophies'] ?? []);
		$characterID = (int) ($trophies['id'] ?? 0);
		if ($characterID <= 0) {
			continue;
		}

		$leaders[] = [
			'characterID' => $characterID,
			'levelCount' => (int) ($trophies['levelCount'] ?? 0),
			'maxLevelCount' => (int) ($trophies['maxLevelCount'] ?? 0),
			'completedPct' => (float) ($trophies['completedPct'] ?? 0),
			'boxes' => max(0, min(5, (int) ($trophies['boxes'] ?? 0))),
			'updated' => (int) ($trophies['calcTrophies_updated'] ?? 0),
		];
	}

	Info::addInfo($leaders);

	$cacheControl = 'public, max-age=3600, s-maxage=3600';
	$response = $response
		->withHeader('Cache-Control', $cacheControl)
		->withHeader('CDN-Cache-Control', $cacheControl)
		->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
		->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT')
		->withHeader('Cache-Tag', 'www,trophies');

	return $container->get('view')->render(
		$response,
		'trophies.pug',
		[
			'leaders' => $leaders,
			'showAds' => true,
		]
	);
}
