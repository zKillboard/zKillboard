<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $URLBASE = "https://zkillboard.com/asearch/";
    
    try {
        $queryParams = $request->getQueryParams();
        $url = rawurldecode((string) ($queryParams['url'] ?? ''));
        $record = $mdb->findDoc("shortener", ['url' => $url]);
        if ($record == null) {
            if (substr($url, 0, strlen($URLBASE)) != $URLBASE) throw new Exception("invalid domain: $url");

            $mdb->insert("shortener", ['url' => $url]);
            $record = $mdb->findDoc("shortener", ['url' => $url]);
        }
        $id = (string) $record['_id'];
        $output = "https://zkillboard.com/asearchsaved/$id/";
    } catch (Exception $ex) {
        $output = $ex->getMessage();
    }
    
    $response->getBody()->write($output);
    return $response;
}
