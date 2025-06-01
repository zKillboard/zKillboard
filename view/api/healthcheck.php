<?php

$app->contentType('application/json; charset=utf-8');
global $mdb, $redis;

$res = ['host' => gethostname()];

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
} catch (Exception $e) {
    $res['mongo'] = false;
    $res['mongo-error'] = $e->getMessage();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
echo json_encode($res);
