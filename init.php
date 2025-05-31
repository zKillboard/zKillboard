<?php

date_default_timezone_set('UTC');

// Ensure PHP 5.5 or higher
if (version_compare(phpversion(), '5.5', '<')) {
    die('PHP 5.5 or higher is required');
}

// config load
require_once 'config.php';

if ($debug) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// vendor autoload
require 'vendor/autoload.php';

$mdb = new Mdb();

connectRedis();

function connectRedis() {
    global $redis, $redisServers, $redisServer, $redisPort;

    $redisServer = @file_get_contents("master.lock");
    if ($redisServer != "") array_unshift($redisServers, $redisServer); // try the proven one first

    foreach ($redisServers as $redisServer) {
        $cli = (php_sapi_name() == "cli");
        $ex = null;
        $loaded = false;
        $attempts = 0;
        do {
            $attempts++;
            try {
                $redis = new Redis();
                $redis->connect($redisServer, $redisPort, 1, '', 100);
                $redis->clearLastError();
                $loaded = true;
                return $redis;
            } catch (Exception $exx) {
                continue;
                $loaded = false;
                $ex = $exx;
                sleep($attempts);
            }
        } while ($cli == true && $loaded == false && $attempts <= 90);
        if ($loaded == false) {
            if ($cli) {
                Util::out("Unable to load Redis: " . $ex->getMessage());
            } else {
                header('HTTP/1.0 503 Server error.');
                echo "<html><head><meta http-equiv='refresh' content='10'></head><body><h3>Redis is currently loading... please wait a few moments</h3></body></html>";
            }
            exit();
        }
    }
}
