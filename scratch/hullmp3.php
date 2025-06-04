<?php

require_once "../init.php";

$redis->publish("public", '{"action":"audio","uri":"/audio/hull.mp3"}');
