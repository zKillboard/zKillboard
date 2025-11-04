<?php

function handler($request, $response, $args, $container) {
    global $redis;

    $imageMap = ['typeID' => 'Type/%1$d_32.png', 'groupID' => 'Type/1_32.png', 'characterID' => 'Character/%1$d_32.jpg', 'corporationID' => 'Corporation/%1$d_32.png', 'allianceID' => 'Alliance/%1$d_32.png', 'factionID' => 'Alliance/%1$d_32.png'];

    // Handle different request methods and parameter sources
    if ($request->getMethod() === 'POST') {
        $postData = $request->getParsedBody();
        $search = $postData['query'] ?? '';
        $entityType = null;
    } else {
        // GET request parameters
        $search = $args['search'] ?? '';
        $entityType = $args['entityType'] ?? null;
    }

    $result = zkbSearch::getResults(ltrim($search), $entityType);
    if (sizeof($result) == 0) $result = zkbSearch::getResults(trim($search), $entityType);

    // Return JSON response with CORS headers
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withHeader('Access-Control-Allow-Origin', '*')
                        ->withHeader('Access-Control-Allow-Methods', 'GET, POST');
    
    $response->getBody()->write(json_encode($result));
    return $response;
}
