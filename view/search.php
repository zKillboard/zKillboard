<?php

global $mdb;

$entities = array();
if ($_POST) {
	$app->redirect('/search/'.urlencode($_POST['searchbox']).'/');
}

$regex = new MongoRegex("/^$search.*/i");
$result = $mdb->find("information", ['cacheTime' => 3600, 'name' => $regex], ['name' => 1], 10);

// if there is only one result, we redirect.
if (count($result) == 1) {
	$first = array_shift($result);
	$type = str_replace('ID', '', $first['type']);
	$id = $first['id'];
	$app->redirect("/$type/$id/");
	die();
}
$entities = [];
foreach ($result as $row) {
	$entity = [];
	$entity['type'] = str_replace('ID', '', $row['type']);
	$entity[$row['type']] = $row['id'];
	$entity[$entity['type'].'Name'] = $row['name'];
	if ($entity['type'] == 'type') {
		$entity['type'] = 'item';
	}
	if ($entity['type'] == 'solarSystem') {
		$entity['type'] = 'system';
	}
	$entities[] = $entity;
}
Info::addInfo($entities);

$app->render('search.html', array('data' => $entities));
