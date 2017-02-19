<?php

use cvweiss\redistools\RedisSessionHandler;
use cvweiss\redistools\RedisTtlCounter;

$pageLoadMS = microtime(true);

$uri = @$_SERVER['REQUEST_URI'];
$isApiRequest = substr($uri, 0, 5) == "/api/";

if ($uri == "/kill/-1/") {
    header("Location: /keepstar1.html");
    exit();
}
// Some killboards and bots are idiots
if (strpos($uri, "_detail") !== false) {
    header('HTTP/1.1 404 This is not an EDK killboard.');
    exit();
}
// Check to ensure we have a trailing slash, helps with caching
if (substr($uri, -1) != '/' && strpos($uri, 'ccpcallback') === false) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    if ($isApiRequest) header("HTTP/1.1 400 Fix your code to include the trailing slash '/'");
    else header("Location: $uri/", true, 301);
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

$ip = IP::get();

// Must rate limit now apparently
$ipKey = "ip:$ip:" . time();
$multi = $redis->multi();
$multi->incr($ipKey, 1);
$multi->expire($ipKey, 3);
$multi->exec();

$limit = $isApiRequest ? 10 : 3;
$noLimits = ['/navbar/', '/post/', '/autocomplete/'];
$noLimit = false;
foreach ($noLimits as $noLimit) $noLimit |= (substr($uri, 0, strlen($noLimit)) === $noLimit);
$count = $redis->get($ipKey);
if ($noLimit === false  && $count >= $limit) {
    header('HTTP/1.1 429 Too many requests.');
    die("<html><head><meta http-equiv='refresh' content='1'></head><body>Rate limited.</body></html>");
}

// Some anti-scraping code, far from perfect though
$badBots = ['mechanize', 'python', 'java'];
if (!$isApiRequest) {
    $userAgent = strtolower(@$_SERVER['HTTP_USER_AGENT']);
    foreach ($badBots as $badBot) {
        if ($userAgent == "" || $userAgent == "-" || strpos($userAgent, $badBot) !== false) {
            header('HTTP/1.1 403 Not authorized.');
            die("APIs are useful, skill up and use that instead.");
        }
    }
}

if (substr($uri, 0, 9) == "/sponsor/" || substr($uri, 0, 11) == '/crestmail/' || $uri == '/navbar/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp') {
    // Session
    session_set_save_handler(new RedisSessionHandler(), true);
    session_cache_limiter('');
    ini_set('session.gc_maxlifetime', $cookie_time);
    session_set_cookie_params($cookie_time);
    session_start();
}

$request = $isApiRequest ? new RedisTtlCounter('ttlc:apiRequests', 300) : new RedisTtlCounter('ttlc:nonApiRequests', 300);
if ($isApiRequest || $uri == '/navbar/') $request->add(uniqid());
$uvisitors = new RedisTtlCounter('ttlc:unique_visitors', 300);
if ($uri == '/navbar/') $uvisitors->add($ip);

$visitors = new RedisTtlCounter('ttlc:visitors', 300);
$visitors->add($ip);
$requests = new RedisTtlCounter('ttlc:requests', 300);
$requests->add(uniqid());

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
