<?php   

function handler($request, $response, $args, $container) {
    global $mdb;    

    $search = $args['search'] ?? null;
    $method = $request->getMethod();
    
    if ($method === 'POST') {   
        $parsedBody = $request->getParsedBody();
        $searchbox = $parsedBody['searchbox'] ?? '';
        return $response->withHeader('Location', '/search/'.urlencode($searchbox).'/')->withStatus(302);
    }   

    $result = zkbSearch::getResults($search);   

    // if there is only one result, we redirect.    
    if (count($result) == 1) {  
        $first = array_shift($result);  
        $type = str_replace('ID', '', $first['type']);  
        $id = $first['id']; 
        return $response->withHeader('Location', "/$type/$id/")->withStatus(302);
    }   

    return $container->view->render($response, 'search.html', array('data' => $result));
}
