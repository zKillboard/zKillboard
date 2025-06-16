<?php

require_once "../init.php";

$m = $mdb->findDoc("killmails");

$r = $redis->get("foo");
