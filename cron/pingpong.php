<?php

require_once "../init.php";

sleep(20);
$redis->publish("public", "ping");
sleep(20);
$redis->publish("public", "pong");
