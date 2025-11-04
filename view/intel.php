<?php

function handler($request, $response, $args, $container) {
    global $redis, $twig;

    $data = array();
    $data['titans']['data'] = unserialize($redis->get('zkb:titans'));
    $data['titans']['title'] = 'Titans';
    $data['supercarriers']['data'] = unserialize($redis->get('zkb:supers'));
    $data['supercarriers']['title'] = 'Supercarriers';

    $uri = $request->getUri()->getPath();
    
    if ($uri == '/intel/supers/') {
        // HTML version
        return $container->get('view')->render($response, 'intel.html', array('data' => $data));
    } else {
        // JSON API version
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Access-Control-Allow-Origin', '*')
                       ->withHeader('Access-Control-Allow-Methods', 'GET')
                       ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
