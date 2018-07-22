<?php

global $mdb;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$row = $mdb->findDoc("prices", ['typeID' => (int) $id]);
unset($row['_id']);
$row['currentPrice'] = Price::getItemPrice((int) $id, null);

if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
    $app->contentType('application/javascript; charset=utf-8');
    header('X-JSONP: true');
    echo $_GET['callback'].'('.json_encode($row).')';
} else {
    $app->contentType('application/json; charset=utf-8');
    echo json_encode($row);
}
