<?php

function handler($request, $response, $args, $container) {
	$documentation = json_decode(
		file_get_contents(__DIR__ . '/../public/api/api.json'),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	return $container->get('view')->render(
		$response->withHeader('Cache-Tag', 'www,api,documentation')->withHeader('Cache-Control', 'public, max-age=3600'),
		'api.pug',
		['sections' => $documentation['sections'], 'showAds' => 0]
	);
}
