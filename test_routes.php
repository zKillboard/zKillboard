<?php
/**
 * Test a few individual routes to see what's working in Slim 3
 */

// Add a simple test route that should work
$app->get('/test-slim3/', function ($request, $response, $args) {
    $response->getBody()->write('Slim 3 is working!');
    return $response;
});

// Add a test route with parameters
$app->get('/test-slim3/{name}/', function ($request, $response, $args) {
    $name = $args['name'];
    $response->getBody()->write("Hello $name from Slim 3!");
    return $response;
});

// Add a test route that uses Twig
$app->get('/test-slim3-twig/', function ($request, $response, $args) {
    return $this->view->render($response, 'simple_test.html', ['message' => 'Twig 3 is working with Slim 3!']);
});
?>