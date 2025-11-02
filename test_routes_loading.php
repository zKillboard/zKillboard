<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test loading routes file standalone
echo "Testing routes.php file for syntax errors...\n";

try {
    chdir('/home/devenv/zKillboard');
    require_once 'init.php';
    
    $app = new \Slim\App(['settings' => $config]);
    include 'twig.php';
    
    echo "About to include routes.php...\n";
    include 'routes.php';
    echo "Routes.php loaded successfully!\n";
    
    // Try to run just the test route
    $_SERVER['REQUEST_URI'] = '/test-slim3/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    echo "About to run test route...\n";
    ob_start();
    $app->run();
    $output = ob_get_clean();
    echo "Output: $output\n";
    
} catch (ParseError $e) {
    echo "Parse Error in routes.php: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} catch (Throwable $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}
?>