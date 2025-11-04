<?php

// Routes configuration - ordered from most specific to most general
$routes = [
	// Redirects
	'/information/' => ['redirect', '/information/about/'],
	'/faq/' => ['redirect', '/information/faq/'],
	'/google/' => ['redirect', '/cache/1hour/google/'],
	'/google/{mobile}/' => ['redirect', '/cache/1hour/google/'],
	
	// GET routes
	'/' => ['GET', 'view/index.php'],
	'/challenge/' => ['GET', 'view/challenge.php'],
	'/cache/1hour/publift/{type}/' => ['GET', 'view/publift.php'],
	'/cache/1hour/google/' => ['GET', 'view/google.php'],
	'/information/{page}/' => ['GET', 'view/information.php'],
	'/account/favorites/' => ['GET', 'view/favorites.php'],
	'/related/{system}/{time}/[o/{options}/]' => ['GET', 'view/related.php'],
	'/br/{battleID}/' => ['GET', 'view/battle_report.php'],
	'/brsave/' => ['GET', 'view/brsave.php'],
	'/bigisk/' => ['GET', 'view/bigisk.php'],
	'/{type}/ranks/{kl}/{solo}/{epoch}/{page}/' => ['GET', 'view/typeRanks.php'],
	'/top/lasthour/{type}/' => ['GET', 'view/lasthour.php'],
	'/kill/{id}/redirect/{where}/' => ['GET', 'view/detail.php'],
	'/kill/{id}/remaining/' => ['GET', 'view/detail.php'],
	'/kill/{id}/' => ['GET', 'view/detail.php'],
	'/kill/{id}/ingamelink/' => ['GET', 'view/detail_ingamelink.php'],
	'/account/logout/' => ['GET', 'view/logout.php'],
	'/account/tracker/{type}/{id}/{action}/' => ['GET', 'view/account_tracker.php'],
	'/item/{id}/' => ['GET', 'view/item.php'],
	'/api/recentactivity/' => ['GET', 'view/api/recentactivity.php'],
	'/api/supers/' => ['GET', 'view/intel.php'],
	'/api/related/{system}/{time}/' => ['GET', 'view/api/related.php'],
	'/api/history/{date}/' => ['GET', 'view/api/history.php'],
	'/api/stats/{type}/{id}/' => ['GET', 'view/apistats.php'],
	'/scanalyzer/' => ['GET', 'view/scanalyzer.php'],
	'/cache/{cacheType:bypass|1hour|24hour}/stats/' => ['GET', 'view/ajax/stats.php'],
	'/cache/{cacheType:bypass|1hour|24hour}/killlist/' => ['GET', 'view/ajax/killlist.php'],
	'/cache/{cacheType:bypass|1hour|24hour}/statstop10/' => ['GET', 'view/ajax/statstop10.php'],
	'/cache/{cacheType:bypass|1hour|24hour}/statstopisk/' => ['GET', 'view/ajax/statstopisk.php'],
	'/api/prices/{id}/' => ['GET', 'view/apiprices.php'],
	'/api/{input:.*}' => ['GET', 'view/api.php'],
	'/post/' => ['GET', 'view/postmail.php'],
	'/asearch/' => ['GET', 'view/asearch.php'],
	'/asearchsave/' => ['GET', 'view/asearchsave.php'],
	'/asearchsaved/{id}/' => ['GET', 'view/asearchsaved.php'],
	'/asearchquery/' => ['GET', 'view/asearchquery.php'],
	'/asearchinfo/' => ['GET', 'view/asearchinfo.php'],
	'/cache/1hour/autocomplete/' => ['GET', 'view/search2020.php'],
	'/autocomplete/{entityType}/{search}/' => ['GET', 'view/autocomplete.php'],
	'/autocomplete/{search}/' => ['GET', 'view/autocomplete.php'],
	'/intel/supers/' => ['GET', 'view/intel.php'],
	'/crestmail/{killID}/{hash}/' => ['GET', 'view/crestmail.php'],
	'/war/eligible/' => ['GET', 'view/war_eligible.php'],
	'/war/{warID}/' => ['GET', 'view/war.php'],
	'/wars/' => ['GET', 'view/wars.php'],
	'/ccplogin/' => ['GET', 'view/ccplogin.php'],
	'/ccpcallback/' => ['GET', 'view/ccpcallback.php'],
	'/ccpsavefit/{killID}/' => ['GET', 'view/ccpsavefit.php'],
	'/ccpoauth2/{delay}/' => ['GET', 'view/ccpoauth2.php'],
	'/ccpoauth2/' => ['GET', 'view/ccpoauth2.php'],
	'/ccpoauth2-360noscope/' => ['GET', 'view/ccpoauth2-noscopes.php'],
	'/cache/bypass/login/patreon/' => ['GET', 'view/patreonlogin.php'],
	'/cache/bypass/login/patreonauth/' => ['GET', 'view/patreonauth.php'],
	'/navbar/' => ['GET', 'view/navbar.php'],
	'/ztop/' => ['GET', 'view/ztop.php'],
	'/sponsor/{type}/{killID}/[{value}/]' => ['GET', 'view/sponsor.php'],
	'/kills/sponsored/' => ['GET', 'view/sponsored.php'],
	'/cache/bypass/comment/{pageID}/{commentID}/up/' => ['GET', 'view/comments-up.php'],
	'/cache/{cacheType:1hour|24hour}/killlistrow/{killID}/' => ['GET', 'view/killlistrow.php'],
	'/cache/bypass/healthcheck/' => ['GET', 'view/api/healthcheck.php'],
	
	// POST routes
	'/account/favorite/{killID}/{action}/' => ['POST', 'view/favorite_modify.php'],
	'/api/killmail/add/{killID}/{hash}/' => ['POST', 'view/api/killmail-add.php'],
	'/cache/bypass/scan/' => ['POST', 'view/scanp.php'],
	
	// Mixed routes
	'/account/[{req}/[{reqid}/]]' => [['GET', 'POST'], 'view/account.php'],
	'/search/[{search}/]' => [['GET', 'POST'], 'view/search.php'],
	'/post/' => [['GET', 'POST'], 'view/postmail.php'],
	
	// Catch-all - MUST be last
	'/{input:.*}/' => ['GET', 'view/overview.php'],
];

// Generate all routes from configuration - single iteration
foreach ($routes as $route => [$method, $target]) {
	if ($method === 'redirect') {
		// Handle redirects
		$app->get($route, function ($request, $response, $args) use ($target) {
			return $response->withStatus(302)->withHeader('Location', $target);
		});
	} else {
		// Handle all other routes - normalize method to array and map
		$methods = is_array($method) ? $method : [$method];
		$app->map($methods, $route, function ($request, $response, $args) use ($target, $container) {
			require_once $target;
			return handler($request, $response, $args, $container);
		});
	}
}