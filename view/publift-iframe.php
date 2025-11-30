<?php

function handler($request, $response, $args, $container) {
    global $publift;
    
    $type = $args['type'];
    $fusecode = @$publift[$type];
	
	// need to add Content-Security-Policy: sandbox allow-scripts;
    $response = $response->withHeader('Content-Security-Policy', "sandbox allow-scripts");
    
    return $container->get('view')->render($response, 'publift-iframe.html', ['slot' => $fusecode]);
}