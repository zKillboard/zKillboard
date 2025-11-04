<?php

function handler($request, $response, $args, $container) {
    global $mdb;
    
    $id = $args['id'] ?? 0;
    
    // Set CORS headers
    $response = $response->withHeader('Access-Control-Allow-Origin', '*')
                        ->withHeader('Access-Control-Allow-Methods', 'GET');
    
    $row = $mdb->findDoc("prices", ['typeID' => (int) $id]);
    unset($row['_id']);
    $row['currentPrice'] = Price::getItemPrice((int) $id, null);
    
    $queryParams = $request->getQueryParams();
    
    if (isset($queryParams['callback']) && Util::isValidCallback($queryParams['callback'])) {
        // Handle JSONP output
        $content = $queryParams['callback'] . '(' . json_encode($row) . ')';
        $response = $response->withHeader('Content-Type', 'application/javascript; charset=utf-8')
                            ->withHeader('X-JSONP', 'true');
        $response->getBody()->write($content);
        return $response;
    } else {
        // Handle JSON output
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write(json_encode($row));
        return $response;
    }
}
