<?php

global $mdb;

$id = (int) $id;
$killmail = $mdb->findDoc('killmails', ['killID' => $id]);
unset($killmail['_id']);
header('Content-Type: application/json');
print_r($killmail);
die();
