<?php

$version = time();
$banTime = 3600 + rand(1, 3600);

// admin character
$adminCharacter = 0;
// EveMail
$evemailCharID = $adminCharacter;

// MongoDB
$mongoConnString = 'mongodb://127.0.0.1:27017';

// Redis parameters
$redisServer = '127.0.0.1';
$redisPort = 6379;

// IPs available
$ipsAvailable = array(); // Set it to the external IP(s) you have available

// External Servers
$apiServer = 'https://api.eveonline.com/';
$imageServer = 'https://images.evetech.net/';
$esiServer = 'https://esi.evetech.net';

// cache times
$esiCorpKm = 900;

// Base
$baseFile = __FILE__;
$baseDir = dirname($baseFile).'/';
$baseUrl = '/';
$baseAddr = 'zkillboard.com';
$fullAddr = 'https://'.$baseAddr;
chdir($baseDir);

// Guzzle File Cache
$apiCacheLocation = '/tmp/apiCache/';

// Debug
$debug = false;

// Twig
$twigDebug = true;
$twigCache = '/dev/shm/twigtemplates/';

// Logfile
$logfile = $baseDir.'/cron/logs/zkb.log';

// Cookiiieeeee
$cookie_name = 'zKillboard';
$cookie_ssl = true;
$cookie_time = (86400 * 30);
$cookie_secret = 'cookie';

$beSocial = false;

// Slim config
$config = array(
        'mode' => 'production',
        'debug' => ($debug ? true : false),
        'log.enabled' => false,
        'cookies.secret_key' => $cookie_secret,
        );

$useSemaphores = false;
$semaphoreModulus = 10;

// Load the websocket
$websocket = false;

// Ads / Analytics
$showAds = true;
$dataAdClient = '';
$dataAdSlot = '';
$dataMobileAdSlot = '';

$showAnalytics = false;
$analyticsID = ''; // UA-<number>
$analyticsName = ''; // name

// Twitter
$twitterName = '';
$consumerKey = '';
$consumerSecret = '';
$accessToken = '';
$accessTokenSecret = '';

$adFreeMonthCost = 5000000; // 5 million ISK per month
$banLength = 9600;

# Save killmails to file system if enabled.
$fsKillmails = false;
$parseAscending = false;

// Theme / Style and Name
$killboardName = 'zKillboard';
$theme = 'zkillboard';
$style = 'cyborg';

# Various settings - set to false if running on a private server
$generateSiteMaps = false;
$killmailFirehose = false;
$fetchWars = false;

# RedisQ Password
$redisQAuthUser = '';
$redisQAuthPass = '';
$redisQServer = '';

# The number of ms when a query is considered to be running a long time
$longQueryMS = 25000;

# CREST SSO
$ccpCallback = '';
$ccpClientID = '';
$ccpSecret = '';

$listenRedisQ = false;
$listenRedisQID = '';
$primePrices = true;

# Save analytics
$doAnalytics = false;

$battleSize = 100;

$allowReinforced = false;

$whiteList = [];
$blackList = [];
$validBots = [];
$badBots = [];

$ignoreEntities = [];

# ESI Threads
$esiCharKillmails =  40;
$esiCorpKillmails = 40;
$ssoThrottle = 20;

$adfreeURIS = ['/ztop/'];

// Patreon
$patreon_client_id = '';
$patreon_client_secret = '';
$patreon_redirect_uri = '';

// Twitch
$twitch_client_id = '';
$twitch_client_secret = '';
$twitch_redirect_uri = '';

// Discord
$discordServer = '';
$killBotWebhook = '';
$bigKillBotWebhook = '';
$gankKillBotWebhook = '';

$publift = [
];

function split($a, $b) { return explode($a, $b); }

$currentTime = date('YmdHi');
$specialExpires = ''; // separated date from time for easier reading
$promoImage = '';
$message = '';
$promoURI = '';
$special = "";

$eveKillLatest = '';
