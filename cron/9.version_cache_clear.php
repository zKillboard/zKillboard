<?php

require_once "../init.php";

$key = "version:www";

$storedVersion = $kvc->get($key);
if ($version != $storedVersion) {
    sleep(30);
    Util::out("Site version has changed, clearing www cache tag...");
    $redis->sadd("queueCacheTags", "www");
    $kvc->set($key, $version);
}
