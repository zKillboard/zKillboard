<?php

global $mdb, $redis;

try {
	$type = filter_input(INPUT_GET, 'type');
    $otype = $type;
    if ($type == "systemID") $type = "solarSystemID";
    if ($type == "shipID") $type = "shipTypeID";
	$id = (int) filter_input(INPUT_GET, 'id');

    $info = Info::getInfo($type, $id);
	$name = @$info['name'];
    if ($name == "") $name = "$type $id";

    if ($type ==  "solarSystemID") $name = "$name (" . Info::getInfoField('regionID', (int) @$info['regionID'], "name") . ")";

	header('Access-Control-Allow-Methods: GET,POST');
	$app->contentType('application/json; charset=utf-8');

    $type = $otype;
	echo json_encode(['type' => $type, 'id' => $id, 'name' => $name], true);
} catch (Exception $ex) {
	Util::zout(print_r($ex, true));
}
