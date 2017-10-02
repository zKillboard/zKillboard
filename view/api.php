<?php

global $redis;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');


try {
    $queryString = $_SERVER['QUERY_STRING'];
    if ($queryString != '') {
        header('HTTP/1.0 403 Forbidden - Do not include a query string to evade cache');
        exit();
    }

    if ($redis->get('zkb:allowApi') === 'no') {
        header('HTTP/1.1 503 Server load high, try again later');
        exit();
    }

    $parameters = Util::convertUriToParameters();
    $return = Feed::getKills($parameters);

    $array = array();
    foreach ($return as $json) {
        $result = json_decode($json, true);
        if (isset($parameters['zkbOnly']) && $parameters['zkbOnly'] == true) {
            if (is_array($result)) {
                foreach ($result as $key => $value) {
                    if ($key != 'killID' && $key != 'zkb') {
                        unset($result[$key]);
                    }
                }
            }
        }
        $array[] = $result;
    }
    $app->expires('+1 hour');

    if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
        $app->contentType('application/javascript; charset=utf-8');
        header('X-JSONP: true');
        echo $_GET['callback'].'('.json_encode($array).')';
    } else {
        $app->contentType('application/json; charset=utf-8');
        if (isset($parameters['pretty'])) {
            echo json_encode($array, JSON_PRETTY_PRINT);
        } else {
            echo json_encode($array);
        }
    }
} catch (Exception $ex) {
    //header('HTTP/1.0 503 Server error.');
    header('Content-Type: application/json');
    $error = ['error' => $ex->getMessage()];
    echo json_encode($error);
    die();
}
