<?php

global $mdb, $uri;

$bypass = strpos($uri, "/bypass/") !== false;

// Create mock app object for URI validation if needed
if (isset($GLOBALS['route_args'])) {
	try {
		$mockApp = new class {
			public function notFound() {
				throw new Exception('Not Found');
			}
		};
		$params = URI::validate($mockApp, $uri, ['s' => !$bypass, 'u' => true]);
	} catch (Exception $e) {
		// If validation fails, return empty result
		echo json_encode([]);
		return;
	}
} else {
	$params = URI::validate($app, $uri, ['s' => !$bypass, 'u' => true]);
}

$sequence = $params['s'];
$uri = $params['u'];

$split = split('/', $uri);
$type = @$split[1];
$id = @$split[2];
if ($type != 'label') {
    $type = "${type}ID";
    $id = (int) $id;
}
if ($type == 'shipID') $type = 'shipTypeID';
elseif ($type == 'systemID') $type = 'solarSystemID';

if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['content_type'] = 'application/json; charset=utf-8';
} else {
	$app->contentType('application/json; charset=utf-8');
}
$stats = $mdb->findDoc("statistics", ['type' => $type, 'id' => $id]);
if ($stats == null) $stats = ['sequence' => 0];

$sa = (int) $stats['sequence'];
if ($bypass || "$sa" != "$sequence") {
	if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
		$GLOBALS['redirect_url'] = "/cache/24hour/killlist/?s=$sa&u=$uri";
		return;
	} else {
		header("Location: /cache/24hour/killlist/?s=$sa&u=$uri");
		return;
	}
}

$params = Util::convertUriToParameters($uri);
$page = (int) @$params['page'];
if ($page < 0 || $page > 20) $kills = [];
else $kills = Kills::getKills($params, true);

echo json_encode(array_keys($kills));
