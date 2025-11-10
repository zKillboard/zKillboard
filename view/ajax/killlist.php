<?php

function handler($request, $response, $args, $container) {
    global $mdb, $uri;

    $bypass = strpos($uri, "/bypass/") !== false;

    // Create mock app object for URI validation
    $mockApp = new class {
        public function notFound() {
            throw new Exception('Not Found');
        }
    };

    try {
        $params = URI::validate($mockApp, $uri, ['s' => !$bypass, 'u' => true]);
    } catch (Exception $e) {
        // If validation fails, return empty JSON result
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode([]));
        return $response;
    }

    $sequence = $params['s'];
    $uri = $params['u'];

    $split = explode('/', $uri);  // Fixed deprecated split() function
    $type = @$split[1];
    $id = @$split[2];
    if ($type != 'label') {
        $type = "${type}ID";
        $id = (int) $id;
    }
    if ($type == 'shipID') $type = 'shipTypeID';
    elseif ($type == 'systemID') $type = 'solarSystemID';

    $stats = $mdb->findDoc("statistics", ['type' => $type, 'id' => $id]);
    if ($stats == null) $stats = ['sequence' => 0];

    $sa = (int) $stats['sequence'];
    if ($bypass || "$sa" != "$sequence") {
        // Redirect to proper sequence URL
        $redirectUrl = "/cache/24hour/killlist/?s=$sa&u=$uri";
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    $params = Util::convertUriToParameters($uri);
    $page = (int) @$params['page'];
    if ($page < 0 || $page > 20) $kills = [];
    else $kills = Kills::getKills($params, true);

    // Return JSON response
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    $response->getBody()->write(json_encode(array_keys($kills)));
    return $response;
}
