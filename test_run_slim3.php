<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Simulate what index.php does
    $uri = '/test-slim3/';
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'localhost';
    
    chdir('/home/devenv/zKillboard');
    require_once 'init.php';
    
    $app = new \Slim\App(['settings' => $config]);
    
    // Load twig
    include 'twig.php';
    
    // Add simple test route
    $app->get('/test-slim3/', function ($request, $response, $args) {
        $response->getBody()->write('SUCCESS: Slim 3 is working!');
        return $response;
    });
    
    echo "About to run app...\n";
    $app->run();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} catch (Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
?>