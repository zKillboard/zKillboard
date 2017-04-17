<?php

global $mdb;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $bid = $id;
    $oID = $id;
    $id = (int) $id;
    if ("$id" != "$oID") throw new Exception("$oID is not a valid parameter");
    if ("$bid" != "$id") throw new Exception("$bid is not a valid parameter");

    $array = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
    unset($array['_id']);

    $array['activepvp'] = Stats::getActivePvpStats([$type => [$id]]);
    $array['info'] = $mdb->findDoc('information', ['type' => $type, 'id' => $id]);
    unset($array['info']['_id']);

    //Stats::getSupers($array, $type, $id);

    if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
        $app->contentType('application/javascript; charset=utf-8');
        header('X-JSONP: true');
        echo $_GET['callback'].'('.json_encode($array).')';
    } else {
        $app->contentType('application/json; charset=utf-8');
        echo json_encode($array);
    }
} catch (Exception $ex) {
    //header('HTTP/1.0 503 Server error.');
    header('Content-Type: application/json');
    $error = ['error' => $ex->getMessage()];
    echo json_encode($error);
    die();
}
