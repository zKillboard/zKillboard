<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    chdir('/home/devenv/zKillboard');
    require_once 'init.php';
    
    echo "Init loaded successfully!\n";
    
    $app = new \Slim\App(['settings' => $config]);
    echo "Slim 3 app created successfully!\n";
    
    // Load twig setup
    include 'twig.php'; 
    echo "Twig loaded successfully!\n";
    
    // Test simple route
    $app->get('/test', function ($request, $response, $args) {
        $response->getBody()->write('Hello World');
        return $response;
    });
    
    echo "Route added successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
?>