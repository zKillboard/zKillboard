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

$sentinel = new RedisSentinel('localhost', 26379);
$master = $sentinel->getMasterAddrByName('mymaster');

$redis = new Redis();
$redis->connect($master[0], $master[1]);
