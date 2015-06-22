<?php

// Include Init
require_once( "init.php" );

$timer = new Timer();

// Starting Slim Framework
$app = new \Slim\Slim($config);

// Session
$session = new zKBSession();
session_set_save_handler($session, true);
session_cache_limiter(false);
session_start();

// Check if the user has autologin turned on
if(!User::isLoggedIn()) User::autoLogin();

if (!User::isLoggedIn())
{
        $uri = @$_SERVER["REQUEST_URI"];
        if ($uri != "")
	{
                $contents = $redis->get("cache:$uri");
                if ($contents !== false)
                {
                        echo $contents;
                        exit();
                }

                $_SERVER["requestDttm"] = $mdb->now();
		$qServer = new RedisQueue("queueServer");
		$qServer->push(serialize($_SERVER));
        }
}

// Theme
if(User::isLoggedIn()) $theme = UserConfig::get("theme");
$app->config(array("templates.path" => $baseDir."themes/"));

// Error handling
$app->error(function (\Exception $e) use ($app){ include ( "view/error.php" ); });

// Load the routes - always keep at the bottom of the require list ;)
include( "routes.php" );

// Load twig stuff
include( "twig.php" );

// Load the theme stuff AFTER routes and Twig, so themers can add crap to twig's global space
require_once("themes/zkillboard.php");

// Run the thing!
$app->run();
