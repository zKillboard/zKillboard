<?php

function handler($request, $response, $args, $container) {
    global $cookie_name;

    unset($_SESSION['characterID']);
    unset($_SESSION['characterName']);

    session_regenerate_id(true);
    session_destroy();
    
    return $response->withHeader('Location', '/')->withStatus(302);
}
