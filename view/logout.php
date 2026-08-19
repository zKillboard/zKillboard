<?php

function handler($request, $response, $args, $container) {
    global $cookie_name;

    unset($_SESSION['characterID']);
    unset($_SESSION['characterName']);

    session_regenerate_id(true);
    session_destroy();
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    
    return $response->withHeader('Location', '/')->withStatus(302);
}
