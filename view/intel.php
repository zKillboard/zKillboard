<?php

global $redis, $uri;

$data = array();
$data['titans']['data'] = unserialize($redis->get('zkb:titans'));
$data['titans']['title'] = 'Titans';
$data['supercarriers']['data'] = unserialize($redis->get('zkb:supers'));
$data['supercarriers']['title'] = 'Supercarriers';

if ($uri == '/intel/supers/') {
    if (isset($GLOBALS['route_args'])) {
        $GLOBALS['render_template'] = 'intel.html';
        $GLOBALS['render_data'] = array('data' => $data);
    } else {
        $app->render('intel.html', array('data' => $data));
    }
} else {
    if (isset($GLOBALS['route_args'])) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    } else {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET');
        $app->contentType('application/json; charset=utf-8');
        echo json_encode($data);
    }
}
