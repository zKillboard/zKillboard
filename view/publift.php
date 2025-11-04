<?php

function handler($request, $response, $args, $container) {
    global $publift;
    
    $type = $args['type'];
    $fusecode = @$publift[$type];
    
    $response->getBody()->write("<div data-fuse='$fusecode'></div>");
    return $response;
}