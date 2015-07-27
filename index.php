<?php

// Include Init
require_once 'init.php';

// http requests should already be prevented, but use this just in case
// also prevents sessions from being created without ssl
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] != 'https') {
    $uri = @$_SERVER['REQUEST_URI'];
    header("Location: https://zkillboard.com$uri");
    die();
}

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

// Session
session_set_save_handler(new RedisSessionHandler(), true);
session_cache_limiter(false);
session_start();

$visitors = new RedisTtlCounter('ttlc:visitors', 300);
$visitors->add(IP::get());
$requests = new RedisTtlCounter('ttlc:requests', 300);
$requests->add(uniqid());

$load = getLoad();

// Check if the user has autologin turned on
if ($load < 20 && !User::isLoggedIn()) {
    User::autoLogin();
}
if ($load >= 10) {
    $uri = @$_SERVER['REQUEST_URI'];
    if ($uri != '') {
        $contents = $redis->get("cache:$uri");
        if ($contents !== false) {
            echo $contents;
            exit();
        }

        $_SERVER['requestDttm'] = $mdb->now();
        $qServer = new RedisQueue('queueServer');
        $qServer->push($_SERVER);
    }
}

// Theme
if (User::isLoggedIn()) {
    $theme = UserConfig::get('theme');
}
$app->config(array('templates.path' => $baseDir.'templates/'));

// Error handling
$app->error(function (\Exception $e) use ($app) { include 'view/error.php'; });

// Load the routes - always keep at the bottom of the require list ;)
include 'routes.php';

// Load twig stuff
include 'twig.php';

// Run the thing!
$app->run();

function getLoad()
{
    $output = array();
    $result = exec('cat /proc/loadavg', $output);

    $split = explode(' ', $result);
    $load = $split[0];

    return $load;
}
