<?php

// Temporary test routes
$app->get('/test-slim3/', function ($request, $response, $args) {
    $response->getBody()->write('Slim 3 is working!');
    return $response;
});

$app->get('/test-slim3/{name}/', function ($request, $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello $name from Slim 3!");
    return $response;
});

$app->get('/test-slim3-twig/', function ($request, $response, $args) {
    return $this->view->render($response, 'simple_test.html', ['message' => 'Twig 3 is working with Slim 3!']);
});



$app->get('/information/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/information/about/');
});
$app->get('/faq/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/information/faq/');
});

$app->get('/challenge/', function ($request, $response, $args) {
	include "view/challenge.php";
	return $response;
});

$app->get('/cache/1hour/publift/{type}/', function ($request, $response, $args) {
	global $publift;
	$type = $args['type'];
	echo "<div data-fuse='" . @$publift[$type] . "'></div>";
	return $response;
});
$app->get('/cache/1hour/google/', function ($request, $response, $args) {
	$mobile = false;
	include "view/google.php";
	return $response;
});
$app->get('/google/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/cache/1hour/google/');
});
$app->get('/google/{mobile}/', function ($request, $response, $args) {
	return $response->withStatus(302)->withHeader('Location', '/cache/1hour/google/');
});

$app->get('/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;  // Flag to prevent rendering
	include 'view/index.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback - basic response
	$response->getBody()->write('Homepage data loading...');
	return $response;
});

// Map
$app->get('/map2020/', function ($request, $response, $args) {
	return $this->view->render($response, 'map.html');
});

//  Information about zKillboard
$app->get('/information/{page}/', function ($request, $response, $args) {
	$page = $args['page'];
	
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/information.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Information page loading...');
	return $response;
});

$app->get('/account/favorites/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	include 'view/favorites.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Favorites loading...');
	return $response;
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
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$system = $args['system'];
	$time = $args['time'];
	$options = $args['options'] ?? '';
	include 'view/related.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// View Battle Report
$app->get('/br/{battleID}/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$battleID = $args['battleID'];
	include 'view/battle_report.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Save Battle Report
$app->get('/brsave/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/brsave.php';
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

// View Battle Report  
$app->get('/bigisk/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	include 'view/bigisk.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Bigisk loading...');
	return $response;
});

$app->get('/{type}/ranks/{kl}/:solo/{epoch}/:page/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$type = $args['type'];
	$kl = $args['kl'];
	$solo = $args['solo'];
	$epoch = $args['epoch'];
	$page = $args['page'];
	include 'view/typeRanks.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// View top
$app->get('/top/lasthour/{type}/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$type = $args['type'];
	include 'view/lasthour.php';
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
$app->get('/top(/{type})(/{page})(/{time:.*})/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$type = $args['type'] ?? 'weekly';
	$page = $args['page'] ?? null;
	$time = $args['time'] ?? array();
	include 'view/top.php';
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

$app->get('/kill/{id}/redirect/{where}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	$GLOBALS['pageview'] = '';
	include 'view/detail.php';
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
	$response->getBody()->write('Kill detail loading...');
	return $response;
});
$app->get('/kill/{id}/remaining/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	$GLOBALS['pageview'] = 'remaining';
	include 'view/detail.php';
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
	$response->getBody()->write('Kill detail loading...');
	return $response;
});
$app->get('/kill/{id}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	$GLOBALS['pageview'] = '';
	include 'view/detail.php';
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
	$response->getBody()->write('Kill detail loading...');
	return $response;
});
$app->get('/kill/{id}/ingamelink/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$id = $args['id'];
	include 'view/detail_ingamelink.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Logout
$app->get('/account/logout/', function ($request, $response, $args) {
	global $cookie_name, $cookie_time, $baseAddr;
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/logout.php';
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

$app->get('/account/tracker/{type}/:id/{action}/', function ($request, $response, $args) {
	include 'view/account_tracker.php';
	return $response;
});

// Account
$app->map(['GET', 'POST'], '/account/[{req}/[{reqid}/]]', function ($request, $response, $args) {
	global $cookie_name, $cookie_time;
	$req = $args['req'] ?? null;
	$reqid = $args['reqid'] ?? null;
	
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/account.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Account loading...');
	return $response;
});

// EveInfo
$app->get('/item/{id}/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	$id = $args['id'];
	include 'view/item.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});


$app->get('/api/recentactivity/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/api/recentactivity.php';
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
	$response->getBody()->write('API loading...');
	return $response;
});
$app->post('/api/killmail/add/{killID}/:hash/', function ($request, $response, $args) {
	include 'view/api/killmail-add.php';
	return $response;
});

$app->get('/api/supers/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/intel.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/api/related/{system}/:time/', function ($request, $response, $args) {
	$mc = RelatedReport::generateReport($system, $time, "[]");
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

$app->get('/api/stats/{type}/:id/', function ($request, $response, $args) {
	include 'view/apistats.php';
	return $response;
});

$app->get('/scanalyzer/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	include 'view/scanalyzer.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Scanalyzer loading...');
	return $response;
});
$app->post('/cache/bypass/scan/', function ($request, $response, $args) {
	include 'view/scanp.php';
	return $response;
});

$app->get('/cache/bypass/stats/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/stats.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/1hour/stats/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/stats.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/24hour/stats/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/stats.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

$app->get('/cache/bypass/killlist/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/killlist.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/1hour/killlist/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/killlist.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/24hour/killlist/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/killlist.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['redirect_url'])) {
		$response = $response->withHeader('Location', $GLOBALS['redirect_url']);
		unset($GLOBALS['redirect_url']);
		return $response->withStatus(302);
	}
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

$app->get('/cache/bypass/statstop10/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/statstop10.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/1hour/statstop10/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/statstop10.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});
$app->get('/cache/24hour/statstop10/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/ajax/statstop10.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

$app->get('/cache/bypass/statstopisk/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/ajax/statstopisk.php';
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
	$response->getBody()->write('Stats loading...');
	return $response;
});
$app->get('/cache/1hour/statstopisk/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/ajax/statstopisk.php';
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
	$response->getBody()->write('Stats loading...');
	return $response;
});
$app->get('/cache/24hour/statstopisk/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/ajax/statstopisk.php';
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
	$response->getBody()->write('Stats loading...');
	return $response;
});

$app->get('/api/prices/{id}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/apiprices.php';
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
	$response->getBody()->write('Price API loading...');
	return $response;
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
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/postmail.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Post loading...');
	return $response;
});
$app->post('/post/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	include 'view/postmail.php';
	ob_end_clean();
	
	// Check if we got a redirect response
	if (isset($GLOBALS['redirect_response'])) {
		return $GLOBALS['redirect_response'];
	}
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Post processing...');
	return $response;
});

// Search
$app->map(['GET', 'POST'], '/search/[{search}/]', function ($request, $response, $args) {
	$search = $args['search'] ?? null;
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/search.php';
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
	$response->getBody()->write('Search loading...');
	return $response;
});

// Advanced Search
$app->map(['GET', 'POST'], '/asearch/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	include 'view/asearch.php';
	ob_end_clean();
	
	// The view should have set render data
	if (isset($GLOBALS['render_template']) && isset($GLOBALS['render_data'])) {
		return $this->view->render($response, $GLOBALS['render_template'], $GLOBALS['render_data']);
	}
	
	// Fallback
	$response->getBody()->write('Advanced search loading...');
	return $response;
});
$app->map(['GET', 'POST'], '/asearchsave/', function ($request, $response, $args) {
	include 'view/asearchsave.php';
	return $response;
});
$app->map(['GET', 'POST'], '/asearchsaved/{id}/', function ($request, $response, $args) {
	$id = $args['id'];
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/asearchsaved.php';
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
	$response->getBody()->write('Advanced search loading...');
	return $response;
});
$app->map(['GET', 'POST'], '/asearchquery/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['route_args'] = $args;
	include 'view/asearchquery.php';
	$output = ob_get_clean();
	
	$response->getBody()->write($output);
	return $response;
});
$app->map(['GET', 'POST'], '/asearchinfo/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/asearchinfo.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['content_type'])) {
		$response = $response->withHeader('Content-Type', $GLOBALS['content_type']);
		unset($GLOBALS['content_type']);
	}
	
	$response->getBody()->write($output);
	return $response;
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
$app->post('/autocomplete/', function ($request, $response, $args) {
	include 'view/autocomplete.php';
	return $response;
});
$app->get('/autocomplete/{entityType}/{search}/', function ($request, $response, $args) {
	$entityType = $args['entityType'];
	$search = $args['search'];
	include 'view/autocomplete.php';
	return $response;
});
$app->get('/autocomplete/{search}/', function ($request, $response, $args) {
	$search = $args['search'];
	include 'view/autocomplete.php';
	return $response;
});

// Intel
$app->get('/intel/supers/', function ($request, $response, $args) {
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['route_args'] = $args;
	include 'view/intel.php';
	$output = ob_get_clean();
	
	if (isset($GLOBALS['render_data'])) {
		global $twig;
		$output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
		unset($GLOBALS['render_data'], $GLOBALS['render_template']);
	}
	
	$response->getBody()->write($output);
	return $response;
});

// Sharing Crest Mails
$app->get('/crestmail/{killID}/:hash/', function ($request, $response, $args) {
	include 'view/crestmail.php';
	return $response;
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
	global $uri;
	$GLOBALS['route_args'] = $args;
	ob_start();
	include 'view/navbar.php';
	$output = ob_get_clean();
	if (isset($GLOBALS['capture_render_data'])) {
		$response->getBody()->write($GLOBALS['capture_render_data']);
		return $response;
	}
	$response->getBody()->write($output);
	return $response;
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
$app->get('/sponsor/{type}/:killID/[{value}/]', function ($request, $response, $args) {
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

$app->get('/cache/bypass/comment/{pageID}/:commentID/up/', function ($request, $response, $args) {
	include 'view/comments-up.php';
	return $response;
});

$app->get('/cache/1hour/killlistrow/{killID}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/killlistrow.php';
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
	$response->getBody()->write('Kill list loading...');
	return $response;
});
$app->get('/cache/24hour/killlistrow/{killID}/', function ($request, $response, $args) {
	// Include the view logic but capture the data instead of rendering
	ob_start();
	$GLOBALS['capture_render_data'] = true;
	$GLOBALS['slim3_response'] = $response;  // Pass response for redirects
	$GLOBALS['route_args'] = $args;  // Pass route arguments to view
	include 'view/killlistrow.php';
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
	$response->getBody()->write('Kill list loading...');
	return $response;
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
