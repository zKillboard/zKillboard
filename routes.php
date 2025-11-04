<?php

$app->get('/information/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/information/about/');
});
$app->get('/faq/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/information/faq/');
});

$app->get('/challenge/', function ($request, $response, $args) {
	require_once 'view/challenge.php';
	return handler($request, $response, $args, $this);
});

$app->get('/cache/1hour/publift/{type}/', function ($request, $response, $args) {
	global $publift;
	$type = $args['type'];
	$response->getBody()->write("<div data-fuse='" . @$publift[$type] . "'></div>");
	return $response;
});
$app->get('/cache/1hour/google/', function ($request, $response, $args) {
	require_once 'view/google.php';
	return handler($request, $response, $args, $this);
});
$app->get('/google/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/cache/1hour/google/');
});
$app->get('/google/{mobile}/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/cache/1hour/google/');
});

$app->get('/', function ($request, $response, $args) {
	require_once 'view/index.php';
	return handler($request, $response, $args, $this);
});

// Map
$app->get('/map2020/', function ($request, $response, $args) {
	return $this->view->render($response, 'map.html');
});

//  Information about zKillboard
$app->get('/information/{page}/', function ($request, $response, $args) {
	require_once 'view/information.php';
	return handler($request, $response, $args, $this);
});

$app->get('/account/favorites/', function ($request, $response, $args) {
	require_once 'view/favorites.php';
	return handler($request, $response, $args, $this);
});
$app->post('/account/favorite/{killID}/{action}/', function ($request, $response, $args) {
	$killID = $args['killID'];
	$action = $args['action'];
	include 'view/favorite_modify.php';
	return $response;
});

$app->get('/related/{system}/{time}', function ($request, $response, $args) {
	$system = $args['system'];
	$time = $args['time'];
	return $response->withStatus(302)->withHeader('Location', "/related/$system/$time/");
});
$app->get('/related/{system}/{time}/[o/{options}/]', function ($request, $response, $args) {
	require_once 'view/related.php';
	return handler($request, $response, $args, $this);
});

// View Battle Report
$app->get('/br/{battleID}/', function ($request, $response, $args) {
	require_once 'view/battle_report.php';
	return handler($request, $response, $args, $this);
});

// Save Battle Report
$app->get('/brsave/', function ($request, $response, $args) {
	require_once 'view/brsave.php';
	return handler($request, $response, $args, $this);
});

// Big ISK Kills
$app->get('/bigisk/', function ($request, $response, $args) {
	require_once 'view/bigisk.php';
	return handler($request, $response, $args, $this);
});

$app->get('/{type}/ranks/{kl}/{solo}/{epoch}/{page}/', function ($request, $response, $args) {
	require_once 'view/typeRanks.php';
	return handler($request, $response, $args, $this);
});

// Top Last Hour
$app->get('/top/lasthour/{type}/', function ($request, $response, $args) {
	require_once 'view/lasthour.php';
	return handler($request, $response, $args, $this);
});

$app->get('/kill/{id}/redirect/{where}/', function ($request, $response, $args) {
	require_once 'view/detail.php';
	return handler($request, $response, $args, $this);
});
$app->get('/kill/{id}/remaining/', function ($request, $response, $args) {
	require_once 'view/detail.php';
	return handler($request, $response, $args, $this);
});
$app->get('/kill/{id}/', function ($request, $response, $args) {
	require_once 'view/detail.php';
	return handler($request, $response, $args, $this);
});
$app->get('/kill/{id}/ingamelink/', function ($request, $response, $args) {
	require_once 'view/detail_ingamelink.php';
	return handler($request, $response, $args, $this);
});

// Logout
$app->get('/account/logout/', function ($request, $response, $args) {
	require_once 'view/logout.php';
	return handler($request, $response, $args, $this);
});

$app->get('/account/tracker/{type}/{id}/{action}/', function ($request, $response, $args) {
	include 'view/account_tracker.php';
	return $response;
});

// Account
$app->map(['GET', 'POST'], '/account/[{req}/[{reqid}/]]', function ($request, $response, $args) {
	require_once 'view/account.php';
	return handler($request, $response, $args, $this);
});

// EveInfo
$app->get('/item/{id}/', function ($request, $response, $args) {
	require_once 'view/item.php';
	return handler($request, $response, $args, $this);
});


$app->get('/api/recentactivity/', function ($request, $response, $args) {
	require_once 'view/api/recentactivity.php';
	return handler($request, $response, $args, $this);
});
$app->post('/api/killmail/add/{killID}/{hash}/', function ($request, $response, $args) {
	require_once 'view/api/killmail-add.php';
	return handler($request, $response, $args, $this);
});

$app->get('/api/supers/', function ($request, $response, $args) {
	require_once 'view/intel.php';
	return handler($request, $response, $args, $this);
});
$app->get('/api/related/{system}/{time}/', function ($request, $response, $args) {
	$mc = RelatedReport::generateReport($args['system'], $args['time'], "[]");
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET');
	$app->contentType('application/json; charset=utf-8');
	echo json_encode($mc, JSON_PRETTY_PRINT);
});

$app->get('/api/history/{date}/', function ($request, $response, $args) {
	$date = $args['date'];
	$response = $response->withHeader('Location', "/api/history/$date.json")->withStatus(302);
	return $response;
});

$app->get('/api/stats/{type}/{id}/', function ($request, $response, $args) {
	include 'view/apistats.php';
	return $response;
});

$app->get('/scanalyzer/', function ($request, $response, $args) {
	require_once 'view/scanalyzer.php';
	return handler($request, $response, $args, $this);
});
$app->post('/cache/bypass/scan/', function ($request, $response, $args) {
	include 'view/scanp.php';
	return $response;
});

$app->get('/cache/{cacheType:bypass|1hour|24hour}/stats/', function ($request, $response, $args) {
	require_once 'view/ajax/stats.php';
	return handler($request, $response, $args, $this);
});

$app->get('/cache/{cacheType:bypass|1hour|24hour}/killlist/', function ($request, $response, $args) {
	require_once 'view/ajax/killlist.php';
	return handler($request, $response, $args, $this);
});

$app->get('/cache/{cacheType:bypass|1hour|24hour}/statstop10/', function ($request, $response, $args) {
	require_once 'view/ajax/statstop10.php';
	return handler($request, $response, $args, $this);
});

$app->get('/cache/{cacheType:bypass|1hour|24hour}/statstopisk/', function ($request, $response, $args) {
	require_once 'view/ajax/statstopisk.php';
	return handler($request, $response, $args, $this);
});

$app->get('/api/prices/{id}/', function ($request, $response, $args) {
	require_once 'view/apiprices.php';  
	return handler($request, $response, $args, $this);
});

$app->get('/api/{input:.*}', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/api.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// Check if we got JSON output
	if (isset($GLOBALS['json_output'])) {
		$response->getBody()->write($GLOBALS['json_output']);
		$contentType = $GLOBALS['json_content_type'] ?? 'application/json; charset=utf-8';
		return $response->withHeader('Content-Type', $contentType);
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('API loading...');
	return $response;
});

// Post
$app->get('/post/', function ($request, $response, $args) {
	require_once 'view/postmail.php';  
	return handler($request, $response, $args, $this);
});
$app->post('/post/', function ($request, $response, $args) {
	require_once 'view/postmail.php';  
	return handler($request, $response, $args, $this);
});

// Search
$app->map(['GET', 'POST'], '/search/[{search}/]', function ($request, $response, $args) {
	require_once 'view/search.php';  
	return handler($request, $response, $args, $this);
});

// Advanced Search
$app->map(['GET'], '/asearch/', function ($request, $response, $args) {
	require_once 'view/asearch.php';  
	return handler($request, $response, $args, $this);
});
$app->map(['POST'], '/asearchsave/', function ($request, $response, $args) {
	require_once 'view/asearchsave.php';  
	return handler($request, $response, $args, $this);
});
$app->map(['GET'], '/asearchsaved/{id}/', function ($request, $response, $args) {
	require_once 'view/asearchsaved.php';  
	return handler($request, $response, $args, $this);
});
$app->map(['GET'], '/asearchquery/', function ($request, $response, $args) {
	require_once 'view/asearchquery.php';  
	return handler($request, $response, $args, $this);
});
$app->map(['GET'], '/asearchinfo/', function ($request, $response, $args) {
	require_once 'view/asearchinfo.php';  
	return handler($request, $response, $args, $this);
});

$app->get('/cache/1hour/autocomplete/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/search2020.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Autocomplete
/*$app->post('/autocomplete/', function ($request, $response, $args) {
	require_once 'view/autocomplete.php';
	return handler($request, $response, $args, $this);
});*/
$app->get('/autocomplete/{entityType}/{search}/', function ($request, $response, $args) {
	require_once 'view/autocomplete.php';
	return handler($request, $response, $args, $this);
});
$app->get('/autocomplete/{search}/', function ($request, $response, $args) {
	require_once 'view/autocomplete.php';
	return handler($request, $response, $args, $this);
});

// Intel
$app->get('/intel/supers/', function ($request, $response, $args) {
	require_once 'view/intel.php';
	return handler($request, $response, $args, $this);
});

// Sharing Crest Mails
$app->get('/crestmail/{killID}/{hash}/', function ($request, $response, $args) {
	require_once 'view/crestmail.php';
	return handler($request, $response, $args, $this);
});

// War!
$app->get('/war/eligible/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/war_eligible.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/war/{warID}/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$warID = $args['warID'];
	include 'view/war.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/wars/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	include 'view/wars.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Wars loading...');
	return $response;
});

// CREST
$app->get('/ccplogin/', function ($request, $response, $args) {
	include 'view/ccplogin.php';
	return $response;
});
$app->get('/ccpcallback/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/ccpcallback.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('CCP callback processing...');
	return $response;
});
$app->get('/ccpsavefit/{killID}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/ccpsavefit.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('CCP Save Fit loading...');
	return $response;
});

// EVE Online OAUTH2
$app->get('/ccpoauth2/{delay}/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$delay = isset($args['delay']) ? $args['delay'] : null;
	include 'view/ccpoauth2.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/ccpoauth2/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$delay = null;
	include 'view/ccpoauth2.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// EVE Online OAUTH2
$app->get('/ccpoauth2-360noscope/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ccpoauth2-noscopes.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Patreon
$app->get('/cache/bypass/login/patreon/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/patreonlogin.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/bypass/login/patreonauth/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/patreonauth.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Twitch
$app->get('/cache/bypass/login/twitch/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/twitchlogin.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return isset($GLOBALS['redirect_status']) ? $response->withStatus($GLOBALS['redirect_status']) : $response->withStatus(302);
	}
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/bypass/login/twitchauth/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/twitchauth.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Twitch auth processing...');
	return $response;
});

$app->get('/navbar/', function ($request, $response, $args) {
	require_once 'view/navbar.php';
	return handler($request, $response, $args, $this);
});

$app->get('/ztop/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	global $twig;
	$output = $twig->render("ztop.html", ['showAds' => false]);
	$response->getBody()->write($output);
	return $response;
});

// Sponsor killmail adjustments
$app->get('/sponsor/{type}/{killID}/[{value}/]', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$type = $args['type'];
	$killID = $args['killID'];
	$value = $args['value'] ?? 0;
	include 'view/sponsor.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
// Sponsored killmails
$app->get('/kills/sponsored/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/sponsored.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

$app->get('/cache/bypass/comment/{pageID}/{commentID}/up/', function ($request, $response, $args) {
	require_once 'view/comments-up.php';
	return handler($request, $response, $args, $this);
});

$app->get('/cache/{cacheType:1hour|24hour}/killlistrow/{killID}/', function ($request, $response, $args) {
	require_once 'view/killlistrow.php';
	return handler($request, $response, $args, $this);
});
$app->get('/cache/bypass/healthcheck/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/api/healthcheck.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// Check if we got JSON output
	if (isset($GLOBALS['json_output'])) {
		$response->getBody()->write($GLOBALS['json_output']);
		return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Healthcheck loading...');
	return $response;
});

// The Overview stuff
$app->get('/{input:.*}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/overview.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// Check if we got JSON output
	if (isset($GLOBALS['json_output'])) {
		$response->getBody()->write($GLOBALS['json_output']);
		return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		$status = $GLOBALS['render_status'] ?? 200;
		return $this->view->render($response->withStatus($status), $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Overview loading...');
	return $response;
});
