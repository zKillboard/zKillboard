<?php

$version = 0;

$characters = [];
$corporations = [];
$alliances = [];
$whiteList = [];
$blackList = [];

// Listen to all killmails?
$listenRedisQ = false;

// Database parameters
$dbUser = 'root';
$dbPassword = 'password';
$dbName = 'zkillboard';
$dbHost = 'localhost';
$dbSocket = null;
$dbExplain = false;
$enableAnalyze = false;

// MongoDB
$mongoServer = '127.0.0.1';
$mongoPort = 27017;

// Redis parameters
$redisServer = '127.0.0.1';
$redisPort = 6379;

// External Servers
$apiServer = 'https://api.eveonline.com/';
$imageServer = 'https://imageserver.eveonline.com/';

// Base
$baseFile = __FILE__;
$baseDir = dirname($baseFile).'/';
$baseUrl = '/';
$baseAddr = 'zkillboard.com';
$fullAddr = 'https://'.$baseAddr;
chdir($baseDir);

// zKillboard
$debug = false;

// Twig
$twigDebug = false;
$twigCache = $baseDir.'/cache/templates/';

// Logfile
$logfile = $baseDir.'/cron/logs/zkb.log';

// Cookiiieeeee
$cookie_name = 'zKillboard';
$cookie_ssl = false;
$cookie_time = (86400 * 14);
$cookie_secret = 'zkb-~auto-secret~';

// Theme / Style and Name
$killboardName = 'zKillboard';
$style = 'cyborg';

# CREST SSO
# Only necessary if you absolutey need people to log into the site
# Setup and configuration is up to you.
$ccpCallback = '';
$ccpClientID = '';
$ccpSecret = '';

# Modify the following settings at your own risk, they are not currently supported for private installs
$beSocial = false;
$showAds = false;
$showAnalytics = false;
$fetchWars = false;
$generateSiteMaps = false;

# The number of ms when a query is considered to be running a long time
$longQueryMS = 30000;

// Slim config
$config = array(
    'mode' => 'production',
    'debug' => ($debug ? true : false),
    'log.enabled' => false,
    'cookies.secret_key' => $cookie_secret,
    );

$topCaPub = '';
$topAdSlot = '';
$bottomCaPub = '';
$bottomAdSlot = '';

$analyticsID = '';
$analyticsName = '';
$disqusSSO = '';
$adFreeMonthCost = 0;
$stompListen = false;

$primePrices = false;

# ESI "Threads"
$esiCharKillmails = 30;
$esiCorpKillmails = 10;

$discordServer = 'https://discord.gg';