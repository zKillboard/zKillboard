<?php

function handler($request, $response, $args, $container) {
    $killID = $args['killID'] ?? 0;

    try {
        $result = ESI::saveFitting($killID);
        $output = "CCP's Response: ".@$result['message'];
        if (isset($result['refid'])) {
            $output .= '<br/>refID: '.$result['refid'];
        }
    } catch (Exception $ex) {
        $output = 'Great Scott! An unexpected error occurred: '.$ex->getMessage();
    }
    
    $response->getBody()->write($output);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
}
