<?php

// We can ignore Disqus
if (@$_SERVER['HTTP_USER_AGENT'] == "Disqus/1.0") die("");

// Check to ensure we have a trailing slash, helps with caching
$uri = @$_SERVER['REQUEST_URI'];
if (substr($uri, -1) != '/') {
	header("Location: $uri/");
	exit();
}

// http requests should already be prevented, but use this just in case
// also prevents sessions from being created without ssl
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https') {
	header("Location: https://zkillboard.com$uri");
	die();
}

// Include Init
require_once 'init.php';

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

// Session
session_set_save_handler(new RedisSessionHandler(), true);
session_cache_limiter(false);
session_start();

$nonApiR = new RedisTtlCounter('ttlc:nonApiRequests', 300);
$apiR = new RedisTtlCounter('ttlc:apiRequests', 300);
if (substr($uri, 0, 5) == '/api/') $apiR->add(uniqid());
else $nonApiR->add(uniqid());

$ip = IP::get();
if ($ip != "127.0.0.1") {
	$visitors = new RedisTtlCounter('ttlc:visitors', 300);
	$visitors->add($ip);
	$requests = new RedisTtlCounter('ttlc:requests', 300);
	$requests->add(uniqid());
}

$load = Load::getLoad();
$isHardened = $redis->get("zkb:isHardened");
if ($ip != "127.0.0.1" && $_SERVER['REQUEST_METHOD'] == 'GET' && ($isHardened || $load >= $loadTripValue)) {
	if ($redis->ttl("zkb:isHardened") < 1) $redis->setex("zkb:isHardened", $loadTripTime, true);
	$qServer = new RedisQueue('queueServer');
	$qServer->push($_SERVER);

	$iterations = 0;
	while ($iterations <= 10) {
		$contents = $redis->get("cache:$uri");
		if ($contents !== false) {
			if ($contents == "reject") header("Location: /");
			else echo $contents;
			exit();
		}
		$iterations++;
		usleep($iterations * 100000);
	}
	header("Location: .");
	exit();
}

// Theme
$theme = UserConfig::get('theme', 'cyborg');
$app->config(array('templates.path' => $baseDir.'templates/'));

// Error handling
$app->error(function (\Exception $e) use ($app) { include 'view/error.php'; });

// Load the routes - always keep at the bottom of the require list ;)
include 'routes.php';

// Load twig stuff
include 'twig.php';

// Run the thing!
$app->run();
