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
if (substr($uri, -1) != '/' && strpos($uri, 'ccpcallback') === false && strpos($uri, 'brsave') === false && strpos($uri, "ccp") === false ) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    // Is there q question mark in the URL? cut it off, doesn't belong
    if (strpos($uri, '?') !== false) {
        /* Facebook and other media sites like to add tracking to the URL... remove it */
        $s = explode('?', $uri);
        $uri = $s[0];
        header("Location: $uri", true, 302);
        exit();
    }
    if ($isApiRequest) header("HTTP/1.1 200 Missing trailing slash");
    else header("Location: $uri/", true, 302);
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

if ($redis->get("zkb:memused") > 115) {
    header('HTTP/1.1 202 API temporarily disabled because of resource limitations');
    exit();
}

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

$ip = IP::get();
$ipE = explode(',', $ip);
$ip = $ipE[0];

if ($redis->get("IP:ban:$ip") == "true") {
    header("Location: /html/banned.html", true, 302);
    return;
}

if (in_array($ip, $blackList)) {
    header('HTTP/1.1 403 Blacklisted');
    die();
}

$limit = 10; 
$noLimits = ['/cache/', '/post/', '/autocomplete/', '/crestmail/', '/comment/', '/killlistrow/', '/comment/', '/related/', '/sponsor', '/crestmail', '/account/', '/logout', '/ccp', '/auto', '/killlistrow/', '/challenge/'];
$noLimit = false;
foreach ($noLimits as $noLimitTxt) $noLimit |= (substr($uri, 0, strlen($noLimitTxt)) === $noLimitTxt);
$count = $redis->get($ip);
if ($noLimit === false  && $count >= $limit) {
    header('HTTP/1.1 429 Too many requests.');
    die("<html><head><meta http-equiv='refresh' content='1'></head><body>Rate limited.</body></html>");
}

// Scrape Checker
$ipKey = "ip::$ip";
if ($redis->get("ip::redirect::$ip") != null) {
    $redis->incr("ip::redirect::$ip:challenges");
    $redis->expire("ip::redirect::$ip:challenges", 3600);
    if ($redis->get("ip::redirect::$ip:challenges") > 10) {
        header("Location: /html/banned.html", true, 302);
        Log::log("Banning $ip for failing to pass challenges. User Agent: " . @$_SERVER['HTTP_USER_AGENT']);
        $redis->setex("IP:ban:$ip", 9600, "true");
        return;
    }
    header("Location: /challenge/", true, 302);
    return;
}
if (!$isApiRequest && !$noLimit && $redis->get("ip::challenge_safe::$ip") != "true") {
    $redis->incr($ipKey, ($uri == '/navbar/' ? -1 : 1));
    $redis->expire($ipKey, 300);
    $count = $redis->get($ipKey);
    if (!in_array($ip, $whiteList) && $count > 40) {
        $host = gethostbyaddr($ip);
        $host2 = gethostbyname($host);
        $isValidBot = false;
        foreach ($validBots as $bot) {
            $isValidBot |= strpos($host, $bot) !== false;
        }
        if ($ip != $host2 || !$isValidBot) {
            if ($redis->get("ip::redirect::$ip") == false) Log::log("Challenging $ip $host $uri");
            $redis->setex("ip::redirect::$ip", 9600, $uri);
            header("Location: /challenge/", true, 302);
            return;
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
    setcookie(session_name(), session_id(), time() + $cookie_time, '/', '.zkillboard.com', true, true);
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

include 'analyticsLoad.php';

// Run the thing!
$app->run();
