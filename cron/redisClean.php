<?php

require_once '../init.php';

$keys = $redis->keys('RC:*');

foreach ($keys as $key) {
    $ttl = $redis->ttl($key);
    if ($ttl <= 10) {
        $redis->del($key);
    }
}
