<?php

function handler($request, $response, $args, $container) {
    global $publift;
    
    $type = $args['type'];
    $fusecode = @$publift[$type];
    
    return $container->get('view')->render($response, 'publift-iframe.html', ['slot' => $fusecode]);
}