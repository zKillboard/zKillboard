<?php

require_once '../init.php';

$i = date('i');
if ($i % 5 != 0) {
    exit();
}

$keys = $redis->keys('RC:*');

foreach ($keys as $key) {
    $ttl = $redis->ttl($key);
    if ($ttl <= 10) {
        $redis->del($key);
    }
}
