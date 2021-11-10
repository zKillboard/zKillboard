<?php

global $mdb, $redis;

try {
	$type = filter_input(INPUT_GET, 'type');
	$id = (int) filter_input(INPUT_GET, 'id');

	$name = Info::getInfoField($type, $id, "name");

	header('Access-Control-Allow-Methods: GET,POST');
	$app->contentType('application/json; charset=utf-8');

	echo json_encode(['type' => $type, 'id' => $id, 'name' => $name], true);
} catch (Exception $ex) {
	Log::log(print_r($ex, true));
}