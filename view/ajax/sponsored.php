<?php

function handler($request, $response, $args, $container)
{
	global $mdb, $uri;

	try {
		$params = URI::validate($uri, ['u' => true]);
	} catch (Exception $e) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,sponsored'), 'components/big_top_list.pug', []);
	}

	$split = explode('/', trim($params['u'], '/'));
	$key = $split[0] ?? '';
	$id = $split[1] ?? 0;
	$types = [
		'character' => 'character',
		'corporation' => 'corporation',
		'alliance' => 'alliance',
		'faction' => 'faction',
		'system' => 'solarSystem',
		'constellation' => 'constellation',
		'region' => 'region',
		'group' => 'group',
		'ship' => 'shipType',
		'location' => 'location',
	];
	if (!isset($types[$key]) || !is_numeric($id) || $id <= 0) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,sponsored'), 'components/big_top_list.pug', []);
	}

	$result = Mdb::group('sponsored', ['killID'], ["victim.{$types[$key]}ID" => (int) $id, 'entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1], 6);
	$sponsored = [];
	foreach ($result as $kill) {
		if ($kill['iskSum'] <= 0) continue;
		$killmail = $mdb->findDoc('killmails', ['killID' => $kill['killID']]);
		if (empty($killmail['involved'][0])) continue;
		Info::addInfo($killmail);
		$killmail['victim'] = $killmail['involved'][0];
		$killmail['zkb']['totalValue'] = $kill['iskSum'];
		$sponsored[$kill['killID']] = $killmail;
	}

	return $container->get('view')->render($response->withHeader('Cache-Tag', "www,sponsored,{$key}:$id"), 'components/big_top_list.pug', [
		'topTitle' => 'Sponsored Killmails - Last 7 Days',
		'topSet' => $sponsored,
	]);
}
