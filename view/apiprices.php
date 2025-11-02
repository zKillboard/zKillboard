<?php

global $mdb;

// Extract route parameters for compatibility
if (isset($GLOBALS['route_args'])) {
    $id = $GLOBALS['route_args']['id'] ?? 0;
} else {
    // Legacy parameter passing still works
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$row = $mdb->findDoc("prices", ['typeID' => (int) $id]);
unset($row['_id']);
$row['currentPrice'] = Price::getItemPrice((int) $id, null);

if (isset($_GET['callback']) && Util::isValidCallback($_GET['callback'])) {
    // Handle JSONP output for compatibility
    if (isset($GLOBALS['capture_render_data'])) {
        header('X-JSONP: true');
        $GLOBALS['json_output'] = $_GET['callback'].'('.json_encode($row).')';
        $GLOBALS['json_content_type'] = 'application/javascript; charset=utf-8';
        return;
    } else {
        $app->contentType('application/javascript; charset=utf-8');
        header('X-JSONP: true');
        echo $_GET['callback'].'('.json_encode($row).')';
    }
} else {
    // Handle JSON output for compatibility
    if (isset($GLOBALS['capture_render_data'])) {
        $GLOBALS['json_output'] = json_encode($row);
        return;
    } else {
        $app->contentType('application/json; charset=utf-8');
        echo json_encode($row);
    }
}
