<?php
/**
 * Fix remaining Slim 2 -> 3 issues in routes.php
 */

$routeFile = 'routes.php';
$content = file_get_contents($routeFile);

// Backup
file_put_contents($routeFile . '.slim3fix', $content);

// Simple search and replace for known patterns
$replacements = [
    // First, fix the search route
    '$app->map(\'/search(/{search})\', function ($search = null) use ($app) {' => 
    '$app->map([\'GET\', \'POST\'], \'/search/[{search}/]\', function ($request, $response, $args) {' . "\n\t" . '$search = $args[\'search\'] ?? null;',
    
    // Fix asearch routes
    '$app->map(\'/asearch/\', function ($search = null) use ($app) {' =>
    '$app->map([\'GET\', \'POST\'], \'/asearch/\', function ($request, $response, $args) {',
    
    '$app->map(\'/asearchquery/\', function ($search = null) use ($app) {' =>
    '$app->map([\'GET\', \'POST\'], \'/asearchquery/\', function ($request, $response, $args) {',
    
    '$app->map(\'/asearchinfo/\', function ($type = null, $id = null) use ($app) {' =>
    '$app->map([\'GET\', \'POST\'], \'/asearchinfo/\', function ($request, $response, $args) {',
    
    // Remove ->via() calls
    '})->via(\'GET\', \'POST\');' => '});',
    '})->via(\'GET\');' => '});',
    '})->via(\'POST\');' => '});',
];

foreach ($replacements as $search => $replace) {
    $content = str_replace($search, $replace, $content);
}

// Save the fixed file  
file_put_contents($routeFile, $content);

echo "Fixed map() and via() calls in routes.php\n";
echo "Backup saved as routes.php.slim3fix\n";
?>