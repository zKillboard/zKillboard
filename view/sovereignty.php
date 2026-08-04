<?php

function handler($request, $response, $args, $container)
{
	global $kvc;

	$alliances = [];
	$snapshot = (array) ($kvc->get('zkb:sovereignty:map') ?? []);
	$totals = (array) ($snapshot['totals'] ?? []);
	if (isset($snapshot['leaderboard'])) $alliances = array_map(function ($alliance) { return (array) $alliance; }, (array) $snapshot['leaderboard']);

	$cacheControl = 'public, max-age=3600, s-maxage=3600';
	return $container->get('view')->render(
		$response
			->withHeader('Cache-Control', $cacheControl)
			->withHeader('CDN-Cache-Control', $cacheControl)
			->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
			->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT')
			->withHeader('Cache-Tag', 'www,sovereignty'),
		'sovereignty.pug',
		[
			'alliances' => $alliances,
			'sovereigntyAvailable' => isset($snapshot['leaderboard'], $snapshot['totals']),
			'sovereigntyUpdatedAt' => (int) ($snapshot['updatedAt'] ?? 0),
			'totalSystems' => (int) ($totals['systems'] ?? 0),
			'totalAlliances' => (int) ($totals['alliances'] ?? 0),
		]
	);
}
