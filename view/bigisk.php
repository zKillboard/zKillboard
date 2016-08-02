<?php

global $redis;

$array = json_decode($redis->get("zkb:topKillsByShip"), true);

$app->render('bigisk.html', array('topSet' => $array));
