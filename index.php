<?php

use cvweiss\redistools\RedisSessionHandler;
use cvweiss\redistools\RedisTtlCounter;

$pageLoadMS = microtime(true);

$uri = @$_SERVER['REQUEST_URI'] ?? '';
$isApiRequest = substr($uri, 0, 5) == "/api/";

if ($uri == "/kill/-1/") return header("Location: /keepstar1.html");

$first7 = substr($uri, 0, 7);
if (strpos($uri, "/asearch") === false && strpos($uri, "/cache/") === false)  {
    // Check to ensure we have a trailing slash, helps with caching
    if (substr($uri, -1) != '/' && strpos($uri, 'ccpcallback') === false && strpos($uri, 'patreon') === false && strpos($uri, 'brsave') === false && strpos($uri, "ccp") === false && strpos($uri, "related/") === false && strpos($uri, 'twitch') == false) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        // Is there a question mark in the URL? cut it off, doesn't belong
        if (strpos($uri, '?') !== false) {
            /* Facebook and other media sites like to add tracking to the URL... remove it */
            $s = explode('?', $uri);
            $uri = $s[0];
            return header("Location: $uri", true, 302);
        }

        if ($isApiRequest) return header("HTTP/1.1 200 Missing trailing slash");
        else return header("Location: $uri/", true, 302);
    }
}

// Include Init
require_once 'init.php';
$ip = IP::get();
$ipE = explode(',', $ip);
$ip = $ipE[0];

$agent = strtolower(@$_SERVER['HTTP_USER_AGENT']);

if ($redis->get("zkb:badbot:$agent") == true) html403("Bad Robot! Naughty! See robots.txt");
$goodBot = $isApiRequest ? true : partialInArray($redis, $agent, $validBots);
$badBot = $goodBot ? false : partialInArray($redis, $agent, $badBots);
if ($badBot) {
    $redis->setex("zkb:badbot:$agent", 86400, "true");
    html403("Bad Robot! Naughty! See robots.txt");
}

//if ($redis->get("IP:ban:$ip") == "true") return header("Location: /html/banned.html", true, 302);
//if (in_array($ip, $blackList)) return header('HTTP/1.1 403 Blacklisted');

// Starting Slim Framework
$app = new \Slim\App(['settings' => $config]);
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'self'");

// Set up the session if we need it for this uri
if (substr($uri, 0, 9) == "/sponsor/" || substr($uri, 0, 11) == '/crestmail/' || $uri == '/navbar/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp' || substr($uri, 0, 20) == "/cache/bypass/login/") {
    session_set_save_handler(new MongoSessionHandler($mdb->getCollection("sessions")), true);
    session_start();
}

if ($isApiRequest || $uri == '/navbar/') {
    $request = $isApiRequest ? new RedisTtlCounter('ttlc:apiRequests', 300) : new RedisTtlCounter('ttlc:nonApiRequests', 300);
    $request->add(uniqid());
    if ($uri == '/navbar/') {
        $uvisitors = new RedisTtlCounter('ttlc:unique_visitors', 300);
        $uvisitors->add($ip);
    }
}

$visitors = new RedisTtlCounter('ttlc:visitors', 300);
$visitors->add($ip);

// Theme
$theme = 'cyborg';

// Load twig stuff BEFORE routes
include 'twig.php';

// Setup error handling for Slim 3
$container = $app->getContainer();
$container['errorHandler'] = function ($c) {
    return function ($request, $response, $exception) use ($c) {
        error_log("Slim 3 Error: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
        $response->getBody()->write("Error: " . $exception->getMessage());
        return $response->withStatus(500);
    };
};

// Load the routes - always keep at the bottom of the require list ;)
include 'routes.php';

// Just some local analytics
include 'analyticsLoad.php';

// Run the thing!
$app->run();

function contains($needle, $haystack) {
    if (is_array($needle)) {
        foreach ($needle as $pin) if (contains($pin, $haystack) !== false) return true;
        return false;
    } 
    return (strpos($haystack, 0, strlen($needle)) !== false);
}

function partialInArray(&$redis, &$haystack, &$needles) {
    $ret = $redis->get("zkb:partial:$haystack");
    if ($ret !== null) {
        $ret = false;
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) $ret = true;
        }
    }
    $redis->setex("zkb:partial:$haystack", 9600, "$ret");
    return (bool) $ret;
}

function html403($reason) {
    header("HTTP/1.1 403 $reason");
    exit();
}
