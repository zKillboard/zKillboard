<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    $hostname = gethostname();
    $res = ['host' => $hostname];

    try {
        $res['redis'] = true;
        $res['redis-error'] = null;
    } catch (Exception $e) {
        $res['redis'] = false;
        $res['redis-error'] = $e->getMessage(); 
    }

    try {
        $mdb->findDoc("killmails");
        $res['mongo'] = true;
        $res['mongo-error'] = null;
        $r = $mdb->getDb()->command(['hello' => 1]);
        $master = $r['primary'] ?? '';
        $res['isMongoPrimary'] = str_contains($master, "${hostname}:");
    } catch (Exception $e) {
        $res['mongo'] = false;
        $res['mongo-error'] = $e->getMessage();
    }

    $response = $response->withHeader('Access-Control-Allow-Origin', '*');
    $response = $response->withHeader('Access-Control-Allow-Methods', 'GET, POST');
    $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    
    $response->getBody()->write(json_encode($res));
    return $response;
}