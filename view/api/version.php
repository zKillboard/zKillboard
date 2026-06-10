<?php

function handler($request, $response, $args, $container) {
    global $version;

    $response = $response
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Tag', 'version')
        ->withHeader('Cache-Control', 'public, max-age=3600');

    $response->getBody()->write(json_encode(['version' => $version]));
    return $response;
}
