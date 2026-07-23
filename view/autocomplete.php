<?php

function handler($request, $response, $args, $container) {
    global $mdb;

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

    if ($entityType == 'ship') {
        $regex = '^' . strtolower(preg_quote(trim($search)));
        $rows = $mdb->find('information', ['type' => 'typeID', 'published' => true, 'categoryID' => 6, 'l_name' => ['$regex' => $regex]], ['l_name' => 1], 15, ['id' => 1]);
        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $info = Info::getInfo('typeID', $id);
            $result[] = [
                'id' => $id,
                'name' => $info['name'] ?? "Ship $id",
                'type' => 'ship',
                'image' => sprintf(zkbSearch::$imageMap['typeID'], $id, 32),
                'pip' => $info['pip'] ?? '',
            ];
        }
    } else {
        $result = zkbSearch::getResults(ltrim($search), $entityType);
        if (sizeof($result) == 0) $result = zkbSearch::getResults(trim($search), $entityType);
    }

    // Return JSON response with CORS headers
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withHeader('Access-Control-Allow-Origin', '*')
                        ->withHeader('Access-Control-Allow-Methods', 'GET, POST')
                        ->withHeader('Cache-Tag', 'www,search,autocomplete');
    
    $response->getBody()->write(json_encode($result));
    return $response;
}
