<?php

global $redis;

if (strlen($date) != 8) exit();

$date = date('Ymd', strtotime($date));
$dayMails = $redis->hGetAll("zkb:day:$date");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
$app->contentType('application/json; charset=utf-8');
echo json_encode($dayMails);
