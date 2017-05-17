<?php

global $redis, $uri;

$data = array();
$data['titans']['data'] = unserialize($redis->get('zkb:titans'));
$data['titans']['title'] = 'Titans';
$data['supercarriers']['data'] = unserialize($redis->get('zkb:supers'));
$data['supercarriers']['title'] = 'Supercarriers';

if ($uri == '/intel/supers/') {
    $app->render('intel.html', array('data' => $data));
} else {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    $app->contentType('application/json; charset=utf-8');
    echo json_encode($data);
}
