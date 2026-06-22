<?php

function handler($request, $response, $args, $container) {
    $system = $args['system'];
    $time = $args['time'];
    
    $mc = RelatedReport::generateReport($system, $time, "[]");
    
    $response = $response->withHeader('Access-Control-Allow-Origin', '*');
    $response = $response->withHeader('Access-Control-Allow-Methods', 'GET');
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    $response = $response->withHeader('Cache-Tag', "api,related,system:$system");
    
    $response->getBody()->write(json_encode($mc, JSON_PRETTY_PRINT));
    return $response;
}
