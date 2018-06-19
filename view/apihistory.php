<?php

global $mdb;

if (strlen($date) != 8) exit();

$date = date('Ymd', strtotime($date));
$dayMails = $mdb->findDoc("daydump", ['day' => $date]);
unset($dayMails['_id']);
unset($dayMails['day']);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
$app->contentType('application/json; charset=utf-8');
echo json_encode($dayMails);
