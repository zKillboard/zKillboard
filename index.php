<?php

use cvweiss\redistools\RedisSessionHandler;
use cvweiss\redistools\RedisTtlCounter;

$pageLoadMS = microtime(true);

// We can ignore Disqus
$agent = @$_SERVER['HTTP_USER_AGENT'];
if (@$_SERVER['HTTP_USER_AGENT'] == 'Disqus/1.0') {
    die('');
}
//$isBot = strpos(strtolower($agent), "bot") !== false;

$uri = @$_SERVER['REQUEST_URI'];
if ($uri == "/kill/-1/") {
    header("Location: /keepstar1.html");
    exit();
}
// Check to ensure we have a trailing slash, helps with caching
if (substr($uri, -1) != '/') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
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

$fetchKey = "fetch:$uri";
$body = $redis->get($fetchKey);
if ($body !== false) {
    $cached = new RedisTtlCounter('ttlc:cached', 300);
    $cached->add(uniqid());
    $ttl = $redis->ttl($fetchKey);
    header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + $ttl));
    header("Cache-Control: public, max-age=$ttl");
    header("X-Cache: HIT");
    echo $body;
    exit();
}

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

// Session
session_set_save_handler(new RedisSessionHandler(), true);
session_cache_limiter('');
if ($uri == '/navbar/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp') {
    ini_set('session.gc_maxlifetime', $cookie_time);
    session_set_cookie_params($cookie_time);
    session_start();
}

$ip = IP::get();

// Must rate limit now apparently
$isApiRequest = substr($uri, 0, 5) == "/api/";
if ($isApiRequest) {
    $ipKey = "ip:$ip:" . time();
    $multi = $redis->multi();
    $multi->incr($ipKey, 1);
    $multi->expire($ipKey, 3);
    $multi->exec();

    if ($redis->get($ipKey) > 3) {
        header('HTTP/1.1 400 Slow down.');
        exit();
    }
}

if ($isApiRequest) {
    $apiR = new RedisTtlCounter('ttlc:apiRequests', 300);
    $apiR->add(uniqid());
} else if ($uri == '/navbar/') {
    $nonApiR = new RedisTtlCounter('ttlc:nonApiRequests', 300);
    $nonApiR->add(uniqid());
} else $redis->rpush("fetchSet", $uri);

if ($ip != '127.0.0.1') {
    $visitors = new RedisTtlCounter('ttlc:visitors', 300);
    $visitors->add($ip);
    $requests = new RedisTtlCounter('ttlc:requests', 300);
    $requests->add(uniqid());
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
