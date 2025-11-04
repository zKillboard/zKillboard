<?php

function handler($request, $response, $args, $container) {
    $date = $args['date'];
    
    return $response->withStatus(302)->withHeader('Location', "/api/history/$date.json");
}