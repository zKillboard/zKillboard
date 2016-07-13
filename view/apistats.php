<?php

global $mdb, $uriParams;

try {
    $parameters = $uriParams;

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => (int) $id]);
    unset($array['_id']);
    $array['activepvp'] = Stats::getActivePvpStats($parameters);
    $array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => (int) $id]);
    unset($array['info']['_id']);

    //Stats::getSupers($array, $type, $id);

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');

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
    header('HTTP/1.0 503 Server error.');
    die();
}
