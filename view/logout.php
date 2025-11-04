<?php

function handler($request, $response, $args, $container) {
    global $cookie_name, $ssoCharacterID, $ssoHash, $redis, $twig;

    if ($ssoCharacterID != null && $ssoHash != null) {
        $value = $redis->del("login:$ssoCharacterID:$ssoHash");
    }

    session_regenerate_id(true);
    session_destroy();
    
    return $response->withHeader('Location', '/')->withStatus(302);
}
