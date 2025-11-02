<?php

global $mdb;

$types = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

foreach ($types as $type) {
    $arr = $mdb->getCollection('ninetyDays')->distinct("involved.$type");
    if (@$arr[0] === null) array_shift($arr);
    if (@$arr[0] === 0) array_shift($arr);
    $json[$type] = $arr;
}

// Handle JSON output for compatibility
if (isset($GLOBALS['capture_render_data'])) {
    $GLOBALS['json_output'] = json_encode($json);
    return;
} else {
    $app->contentType('application/json; charset=utf-8');
    echo json_encode($json);
}
