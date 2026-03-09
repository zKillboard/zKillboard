<?php

require_once "../init.php";

$key = "md:special";

$storedSpecial = $kvc->get($key);
if ($special != $storedSpecial) {
    sleep(30);
    Util::out("MarkeeDragon sale has started, ended, or has been modified...");
    $redis->sadd("queueCacheUrls", "https://zkillboard.com/img/mdpromo1.png");
    $redis->sadd("queueCacheUrls", "https://zkillboard.com/img/mdpromo2.png");
    $redis->sadd("queueCacheTags", "detail");
    $redis->sadd("queueCacheTags", "overview");
    $kvc->set($key, $special);
}
