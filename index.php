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
    $trailingSlashExceptions = ['ccpcallback', 'patreon', 'brsave', 'ccp', 'related/', '.js', '.jpg', '.png', '.css', '.json', '.xml', '.txt', '.ico'];
    $needsTrailingSlash = substr($uri, -1) != '/';
    
    // Check for exceptions
    if ($needsTrailingSlash) {
        foreach ($trailingSlashExceptions as $exception) {
            if (strpos($uri, $exception) !== false) {
                $needsTrailingSlash = false;
                break;
            }
        }
    }
    
    if ($needsTrailingSlash) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        // Is there a question mark in the URL? cut it off, doesn't belong
        if (strpos($uri, '?') !== false) {
            /* Facebook and other media sites like to add tracking to the URL... remove it */
            $s = explode('?', $uri);
            $uri = $s[0];
            return header("Location: $uri", true, 302);
        }

        if ($isApiRequest) {
            header("Cache-Tag: www,error,trailing-slash");
            return header("HTTP/1.1 200 Missing trailing slash");
        }
        else {
            $uri = htmlspecialchars("$uri/", ENT_QUOTES);
            $url = "https://zkillboard.com$uri";

            http_response_code(404);
            header("Link: <$url>; rel=\"canonical\"");
            header("Content-Type: text/html; charset=UTF-8");
            header("Cache-Tag: www,error,404,trailing-slash");
            header("Cache-Control: public, max-age=86400");
            header("Expires: " . gmdate("D, d M Y H:i:s", time() + 86400) . " GMT");
            echo "<!DOCTYPE html><html><head><meta http-equiv=\"refresh\" content=\"15;url=$url\"></head><body>Invalid URL!  Put a slash at the end... like this: <a href=\"$url\">$url</a></body></html>";
            return;
        }
    }
}

// Include Init
require_once 'init.php';
$ip = Util::getIP();
$ipE = explode(',', $ip);
$ip = $ipE[0];

$agent = strtolower(@$_SERVER['HTTP_USER_AGENT']);

// Starting Slim Framework 4
use Slim\Factory\AppFactory;
use DI\Container;

// Create Container
$container = new Container();

// Set container to create App with on AppFactory
AppFactory::setContainer($container);
$app = AppFactory::create();

// Set up the session if we need it for this uri
if (substr($uri, 0, 9) == "/sponsor/" || substr($uri, 0, 11) == '/crestmail/' || $uri == '/navbar/' || substr($uri, 0, 9) == '/account/' || $uri == '/logout/' || substr($uri, 0, 4) == '/ccp' || substr($uri, 0, 20) == "/cache/bypass/login/") {
    session_set_save_handler(new MongoSessionHandler($mdb->getCollection("sessions")), true);
    session_start();
}

// Insert into visitor log without any write concern
$n = $mdb->getCollection("visitorlog")->insertOne(
	['ip' => $ip, 'uri' => $uri, 'api' => $isApiRequest, 'agent' =>  iconv("UTF-8", "UTF-8//IGNORE", $agent), 'dttm' => $mdb->now()],
	['writeConcern' => new MongoDB\Driver\WriteConcern(0)]
);

// Theme
$theme = 'cyborg';

// Load template renderer BEFORE routes
include 'pug.php';

// Add Routing Middleware (required for Slim 4)
$app->addRoutingMiddleware();

// Add default security headers middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    
    // Set default frame options (deny framing for security)
    // Individual routes can override these headers if needed
    if (!$response->hasHeader('X-Frame-Options')) {
        $response = $response->withHeader('X-Frame-Options', 'DENY');
    }
    if (!$response->hasHeader('Content-Security-Policy')) {
        $response = $response->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }
    if (!$response->hasHeader('Content-Security-Policy-Report-Only')) {
        $frameAncestors = $response->getHeaderLine('Content-Security-Policy') == 'frame-ancestors *' ? 'frame-ancestors *' : "frame-ancestors 'self'";
        $response = $response->withHeader('Content-Security-Policy-Report-Only', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            $frameAncestors,
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.datatables.net https://unpkg.com https://cdn.fuseplatform.net",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.datatables.net https://cdnjs.cloudflare.com https://netdna.bootstrapcdn.com",
            "img-src 'self' data: https: http:",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "connect-src 'self' ws: wss:",
            "frame-src 'self' https://zkillboard.com",
            "form-action 'self'",
        ]));
    }
    if (!$response->hasHeader('X-Content-Type-Options')) {
        $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
    }
    if (!$response->hasHeader('Referrer-Policy')) {
        $response = $response->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
    if (!$response->hasHeader('Permissions-Policy')) {
        $response = $response->withHeader('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
    }
    
    return $response;
});

// Setup error handling for Slim 4
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('text/html');

// Custom error handlers to suppress 404/405 logging noise
$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpNotFoundException::class,
    function ($request, $exception, $displayErrorDetails) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $response->getBody()->write('404 Not Found');
        return $response->withStatus(404)->withHeader('Cache-Tag', 'www,error,404');
    }
);

$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpMethodNotAllowedException::class,
    function ($request, $exception, $displayErrorDetails) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $response->getBody()->write('405 Method Not Allowed');
        return $response->withStatus(405)->withHeader('Cache-Tag', 'www,error,405');
    }
);

// Load the routes - always keep at the bottom of the require list ;)
include 'routes.php';

// Run the thing!
$app->run();

function contains($needle, $haystack) {
    if (is_array($needle)) {
        foreach ($needle as $pin) if (contains($pin, $haystack) !== false) return true;
        return false;
    } 
    return (strpos($haystack, 0, strlen($needle)) !== false);
}

function html403($reason) {
    header("Cache-Tag: www,error,403");
    header("HTTP/1.1 403 $reason");
    exit();
}
