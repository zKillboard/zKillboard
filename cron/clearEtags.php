<?php

require_once "../init.php";

$key = "zkb:clearetags:" . date('Ymd');
if ($redis->get($key) == "true") return;

clearKeys($redis, "zkb:etag*");

function clearKeys($redis, $keyBase) {
    $keys = $redis->keys($keyBase);
    foreach ($keys as $key) {
        $redis->del($key);
    }
}
$redis->setex($key, 86400, "true");
