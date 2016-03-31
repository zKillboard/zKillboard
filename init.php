<?php

date_default_timezone_set('UTC');

// Ensure PHP 5.4 or higher
if (version_compare(phpversion(), '5.4.1', '<')) {
    die('PHP 5.4 or higher is required');
}

// config load
require_once 'config.php';

if ($debug) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

// vendor autoload
require 'vendor/autoload.php';

// zkb class autoloader
spl_autoload_register('zkbautoload');

function zkbautoload($class_name)
{
    $baseDir = dirname(__FILE__);
    $fileName = "$baseDir/classes/$class_name.php";
    if (file_exists($fileName)) {
        require_once $fileName;

        return;
    }
}

$mdb = new Mdb();

$redis = new Redis();
$redis->connect($redisServer, $redisPort, 3600);
