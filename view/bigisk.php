<?php

global $redis;

$array = json_decode($redis->get("topKillsByShip"), true);

$app->render('bigisk.html', array('topSet' => $array));
