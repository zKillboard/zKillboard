<?php

// Routes configuration - ordered from most specific to most general
$routes = [
	// Redirects
	'/information/' => ['redirect', '/information/about/'],
	'/faq/' => ['redirect', '/information/faq/'],
	'/google/' => ['redirect', '/cache/1hour/google/'],
	'/google/{mobile}/' => ['redirect', '/cache/1hour/google/'],
	'/market/' => ['redirect', '/market/44992/'],
	
	// GET routes
	'/' => ['GET', 'view/index.php'],
	'/challenge/' => ['GET', 'view/challenge.php'],
	'/cache/1hour/publift/{type}/' => ['GET', 'view/publift.php'],
	'/cache/1hour/google/' => ['GET', 'view/google.php'],
	'/information/{page}/' => ['GET', 'view/information.php'],
	'/account/favorites/' => ['GET', 'view/favorites.php'],
	'/account/favorites/{scope}/' => ['GET', 'view/favorites.php'],
	'/related/{system}/{time}/[o/{options}/]' => ['GET', 'view/related.php'],
	'/br/{battleID}/' => ['GET', 'view/battle_report.php'],
	'/brsave/' => ['GET', 'view/brsave.php'],
	'/bigisk/' => ['GET', 'view/bigisk.php'],
	'/campaigns/' => ['GET', 'view/campaigns/index.php'],
	'/campaign/{uid}/swap/' => ['GET', 'view/campaigns/campaign.php'],
	'/campaign/{uid}/' => ['GET', 'view/campaigns/campaign.php'],
	'/fits/loss/{killID}/' => ['GET', 'view/fits_detail.php'],
	'/fits/detail/{hash}/' => ['GET', 'view/fits_detail.php'],
	'/fits/[{ship}/]' => ['GET', 'view/fits.php'],
	'/simulate/' => ['GET', 'view/simulate.php'],
	'/market/data/' => ['GET', 'view/market_data.php'],
	'/market/{item}/' => ['GET', 'view/market.php'],
	'/{type}/ranks/{kl}/{solo}/{epoch}/{page}/' => ['GET', 'view/typeRanks.php'],
	'/{type}/ranks/{kl}/{solo}/{epoch}/{page}/{sort}/{dir}/' => ['GET', 'view/typeRanks.php'],
	'/top/lasthour/{type}/' => ['GET', 'view/lasthour.php'],
	'/kill/{id}/redirect/{where}/' => ['GET', 'view/detail.php'],
	'/kill/{id}/remaining/' => ['GET', 'view/detail.php'],
	'/kill/{id}/items/' => ['GET', 'view/detail.php'],
	'/kill/{id}/' => ['GET', 'view/detail.php'],
	'/kill/{id}/ingamelink/' => ['GET', 'view/detail_ingamelink.php'],
	'/account/logout/' => ['GET', 'view/logout.php'],
	'/account/tracker/{type}/{id}/{action}/' => ['GET', 'view/account_tracker.php'],
	'/item/{id}/' => ['GET', 'view/item.php'],
	'/api/docs/' => ['GET', 'view/api-docs.php'],
	'/api/version/' => ['GET', 'view/api/version.php'],
	'/api/character-title/{id}/' => ['GET', 'view/api/character-title.php'],
	'/api/recentactivity/' => ['GET', 'view/api/recentactivity.php'],
	'/api/supers/' => ['GET', 'view/intel.php'],
	'/api/related/{system}/{time}/' => ['GET', 'view/api/related.php'],
	'/api/history/{date}/' => ['GET', 'view/api/history.php'],
	'/api/stats/{type}/{id}/{sort:kills|isk}/' => ['GET', 'view/apistats.php'],
	'/api/stats/{type}/{id}/' => ['GET', 'view/apistats.php'],
	'/scanalyzer/' => ['GET', 'view/scanalyzer.php'],
	'/cache/bypass/cachetag/{tag}/' => ['GET', 'view/cachetag.php'],
	'/cache/{cacheType:bypass|1hour|24hour|tagged}/stats/' => ['GET', 'view/ajax/stats.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/killlist/' => ['GET', 'view/ajax/killlist.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/statstop10/' => ['GET', 'view/ajax/statstop10.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/statstopisk/' => ['GET', 'view/ajax/statstopisk.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/sponsored/' => ['GET', 'view/ajax/sponsored.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/activepvp/' => ['GET', 'view/ajax/activepvp.php'],
	'/cache/{cacheType:1hour|24hour|tagged}/activity/' => ['GET', 'view/ajax/activity.php'],
	'/api/prices/date/{date}/' => ['GET', 'view/api/prices-date.php'],
	'/api/prices/{id}/' => ['GET', 'view/apiprices.php'],
	'/api/{input:.*}' => ['GET', 'view/api.php'],
	'/post/' => ['GET', 'view/postmail.php'],
	'/labels/' => ['GET', 'view/labels.php'],
	'/asearch/' => ['GET', 'view/asearch.php'],
	'/asearchsave/' => ['GET', 'view/asearchsave.php'],
	'/asearchsaved/{id}/' => ['GET', 'view/asearchsaved.php'],
	// '/asearch/daily/' => ['GET', 'view/dailyquery.php'],
	'/asearchquery/' => ['GET', 'view/asearchquery.php'],
	'/asearchinfo/' => ['GET', 'view/asearchinfo.php'],
	'/cache/1hour/autocomplete/' => ['GET', 'view/search2020.php'],
	'/autocomplete/{entityType}/{search}/' => ['GET', 'view/autocomplete.php'],
	'/autocomplete/{search}/' => ['GET', 'view/autocomplete.php'],
	'/intel/supers/' => ['GET', 'view/intel.php'],
	'/crestmail/{killID}/{hash}/' => ['GET', 'view/crestmail.php'],
	'/war/eligible/' => ['GET', 'view/war_eligible.php'],
	'/war/{warID}/swap/' => ['GET', 'view/war.php'],
	'/war/{warID}/' => ['GET', 'view/war.php'],
	'/wars/' => ['GET', 'view/wars.php'],
	'/sovereignty/' => ['GET', 'view/sovereignty.php'],
	'/trophies/' => ['GET', 'view/trophies.php'],
	'/ccplogin/' => ['GET', 'view/ccplogin.php'],
	'/ccpcallback/' => ['GET', 'view/ccpcallback.php'],
	'/simulate/save/' => ['POST', 'view/simulate_save.php'],
	'/ccpsavefit/{killID}/' => ['GET', 'view/ccpsavefit.php'],
	'/ccpoauth2/{delay}/' => ['GET', 'view/ccpoauth2.php'],
	'/ccpoauth2/' => ['GET', 'view/ccpoauth2.php'],
	'/ccpoauth2-360noscope/' => ['GET', 'view/ccpoauth2-noscopes.php'],
	'/cache/bypass/login/patreon/' => ['GET', 'view/patreonlogin.php'],
	'/cache/bypass/login/patreonauth' => ['GET', 'view/patreonauth.php'],
	'/navbar/' => ['GET', 'view/navbar.php'],
	'/ztop/' => ['GET', 'view/ztop.php'],
	'/sponsor/{type}/{killID}/[{value}/]' => ['GET', 'view/sponsor.php'],
	'/kills/sponsored/' => ['GET', 'view/sponsored.php'],
	'/cache/{cacheType:1hour|24hour}/killlistrow_v2/{killID}/' => ['GET', 'view/killlistrow_v2.php'],
	'/cache/{cacheType:1hour|24hour}/killlistrow/{killID}/' => ['GET', 'view/killlistrow.php'],
	'/cache/bypass/healthcheck/' => ['GET', 'view/api/healthcheck.php'],
	'/cache/tagged/comment/{pageID}/' => ['GET', 'view/comments.php'],
	'/{type:character|corporation|alliance|faction|system|constellation|region|group|ship|location|label}/{id}/{pageType:daily}/{dailySide:kills|losses}/{dailyDays}/' => ['GET', 'view/overview.php'],
	'/{type:character|corporation|alliance|faction|system|constellation|region|group|ship|location|label}/{id}/{pageType:daily}/{dailyDate}/{dailySide}/' => ['GET', 'view/overview.php'],
	'/{type:character|corporation|alliance|faction|system|constellation|region|group|ship|location|label}/{id}/{pageType:daily}/' => ['GET', 'view/overview.php'],
	
	// POST routes
	'/account/favorite/{killID}/{action}/' => ['POST', 'view/favorite_modify.php'],
	'/account/favorite/{scope}/{killID}/{action}/' => ['POST', 'view/favorite_modify.php'],
	'/api/killmail/add/{killID}/{hash}/[{delay}/]' => ['POST', 'view/api/killmail-add.php'],
	'/cache/bypass/scan/' => ['POST', 'view/scanp.php'],
	'/cache/bypass/comment/{pageID}/{commentID}/up/' => ['POST', 'view/comments-up.php'],
	'/account/campaigns/create/' => ['POST', 'view/campaigns/create.php'],
	'/account/campaigns/matches/' => ['POST', 'view/campaigns/matches.php'],
	'/account/campaigns/update/{uid}/' => ['POST', 'view/campaigns/update.php'],
	
	// Mixed routes
	'/mcp/' => [['GET', 'POST', 'DELETE'], 'view/mcp.php'],
	'/account/[{req}/[{reqid}/]]' => [['GET', 'POST'], 'view/account.php'],
	'/search/[{search}/]' => [['GET', 'POST'], 'view/search.php'],
	'/post/' => [['GET', 'POST'], 'view/postmail.php'],
	
	// Catch-all - MUST be last
	'/{input:.*}/' => ['GET', 'view/overview.php'],
];

// Handler-specific defaults; handlers can set a more specific response policy.
$cachePolicies = [
	'view/detail.php' => 'public, max-age=3600, s-maxage=86400',
	'view/detail_ingamelink.php' => 'public, max-age=3600, s-maxage=86400',
	'view/information.php' => 'public, max-age=3600, s-maxage=86400',
	'view/publift.php' => 'public, max-age=28800, s-maxage=604800',
	'view/challenge.php' => 'no-store',
	'view/brsave.php' => 'no-store',
	'view/asearchsave.php' => 'no-store',
	'view/navbar.php' => 'no-store',
];

foreach ($routes as $route => [$method, $target]) {
	$methods = $method === 'redirect' ? ['GET'] : (is_array($method) ? $method : [$method]);
	$app->map($methods, $route, function ($request, $response, $args) use ($method, $target, $route, $container, $cachePolicies) {
		$hadSession = session_status() === PHP_SESSION_ACTIVE;
		if ($method === 'redirect') {
			$response = $response->withStatus(302)->withHeader('Location', $target);
		} else {
			require_once $target;
			$response = handler($request, $response, $args, $container);
		}

		$cacheControl = $cachePolicies[$target] ?? 'public, max-age=3600';
		if ($hadSession || session_status() === PHP_SESSION_ACTIVE || !in_array($request->getMethod(), ['GET', 'HEAD']) || $response->hasHeader('Set-Cookie') || str_starts_with($request->getUri()->getPath(), '/cache/bypass/') || $cacheControl === 'no-store') {
			return $response->withHeader('Cache-Control', 'no-store')
				->withHeader('CDN-Cache-Control', 'no-store')
				->withHeader('Cloudflare-CDN-Cache-Control', 'no-store')
				->withHeader('Expires', '0');
		}

		if ($response->getStatusCode() >= 400 || in_array('error', explode(',', $response->getHeaderLine('Cache-Tag')))) {
			return $response->withHeader('Cache-Control', 'no-store')
				->withHeader('CDN-Cache-Control', 'public, max-age=60')
				->withHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=60')
				->withHeader('Expires', '0');
		}

		if (!$response->hasHeader('Cache-Control')) {
			if (($args['cacheType'] ?? '') === 'tagged') {
				$cacheControl = 'no-store';
				$response = $response->withHeader('Cloudflare-CDN-Cache-Control', 'public, max-age=28800');
			} elseif (($args['cacheType'] ?? '') === '24hour' || $route === '/information/') {
				$cacheControl = 'public, max-age=3600, s-maxage=86400';
			}
			$response = $response->withHeader('Cache-Control', $cacheControl);
		}
		return $response;
	});
}
