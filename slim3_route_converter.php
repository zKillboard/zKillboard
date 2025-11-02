<?php
/**
 * Script to help convert Slim 2 routes to Slim 3 format
 * This handles the most common patterns automatically
 */

$routeFile = 'routes.php';
$content = file_get_contents($routeFile);

// Backup original
file_put_contents($routeFile . '.backup', $content);

// Convert basic route patterns
$patterns = [
    // Convert route parameters from :param to {param}
    '/(\/:)([a-zA-Z0-9_]+)(\/)/i' => '/{$2}/',
    '/(\/:)([a-zA-Z0-9_]+)(\))/i' => '/{$2})',
    '/(\/:)([a-zA-Z0-9_]+)(\+)/i' => '/{$2:.*}',
    
    // Convert optional segments from (:param/) to [{param}/]
    '/\(\:([a-zA-Z0-9_]+)\/\)/' => '[{$1}/]',
    '/\(\:([a-zA-Z0-9_]+)\)/' => '[{$1}]',
    
    // Convert function signatures - this covers most basic cases
    '/function\s*\(\s*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    '/function\s*\(\s*\$([a-zA-Z0-9_]+)\s*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    '/function\s*\(\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+)\s*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    '/function\s*\(\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+)\s*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    '/function\s*\(\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+)\s*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    '/function\s*\(\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+),\s*\$([a-zA-Z0-9_]+)\s* = [^)]*\)\s*use\s*\(\s*\$app\s*\)/' => 'function ($request, $response, $args)',
    
    // Convert $app->redirect calls to PSR-7 response
    '/\$app->redirect\(\s*["\']([^"\']+)["\']\s*,\s*(\d+)\s*\)/' => 'return $response->withStatus($2)->withHeader(\'Location\', \'$1\')',
    '/\$app->redirect\(\s*["\']([^"\']+)["\']\s*\)/' => 'return $response->withStatus(302)->withHeader(\'Location\', \'$1\')',
    
    // Convert $app->render calls
    '/\$app->render\(\s*["\']([^"\']+)["\']\s*\)/' => 'return $this->view->render($response, \'$1\')',
    '/\$app->render\(\s*["\']([^"\']+)["\']\s*,\s*\$([a-zA-Z0-9_]+)\s*\)/' => 'return $this->view->render($response, \'$1\', $$2)',
];

// Apply transformations
foreach ($patterns as $pattern => $replacement) {
    $content = preg_replace($pattern, $replacement, $content);
}

// Additional manual fixes that need to be more careful
$content = str_replace(
    'include "view/',
    'include "view/',
    $content
);

// Ensure routes that include views return response
$content = preg_replace(
    '/(include ["\'][^"\']*\.php["\'];)(?!\s*return)/',
    '$1' . "\n\treturn \$response;",
    $content
);

// Save the converted file
file_put_contents($routeFile, $content);

echo "Route conversion completed. Original backed up to routes.php.backup\n";
echo "You may need to manually review and fix some complex routes.\n";
?>