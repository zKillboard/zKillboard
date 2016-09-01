<?php

use cvweiss\redistools\RedisSessionHandler;
use cvweiss\redistools\RedisTtlCounter;

$pageLoadMS = microtime(true);

// We can ignore Disqus
$agent = @$_SERVER['HTTP_USER_AGENT'];
if (@$_SERVER['HTTP_USER_AGENT'] == 'Disqus/1.0') {
    die('');
}
$isBot = strpos(strtolower($agent), "bot") !== false;

// Check to ensure we have a trailing slash, helps with caching
$uri = @$_SERVER['REQUEST_URI'];
if ($uri == "/kill/-1/") {
    echo file_get_contents("/var/www/zkillboard.com/special/keepstar.html");
    exit();
}
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

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

// Session
session_set_save_handler(new RedisSessionHandler(), true);
session_cache_limiter(false);
session_start();

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
