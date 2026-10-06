<?php

function handler($request, $response, $args, $container)
{
	global $uri;

	try {
		$params = URI::validate($uri, ['u' => true]);
		$pageUri = $params['u'];
		$parameters = Util::convertUriToParameters($pageUri);
	} catch (Exception $e) {
		return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,activepvp'), 'components/activePvP.pug', []);
	}

	$split = explode('/', trim($pageUri, '/'));
	$activePvP = ($split[0] ?? '') == 'label' ? [] : Stats::getActivePvpStats($parameters);
	$pageType = $split[2] ?? 'overview';

	return $container->get('view')->render($response->withHeader('Cache-Tag', 'www,activepvp'), 'components/activePvP.pug', [
		'activePvP' => $activePvP,
		'pageType' => $pageType,
	]);
}
