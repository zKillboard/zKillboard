<?php

require_once "../init.php";

$is420ed = false;
$minute = date('Hi');
while ($minute == date('Hi') || $is420ed) {
    if ($is420ed) {
        if ($redis->get("zkb:420ed") != "true") {
            $is420ed = false;
            Util::out("420 lifted...");
        }
    } else {
        if ($redis->get("zkb:420ed") == "true") {
            $is420ed = true;
        }
    }
    sleep(1);
}
