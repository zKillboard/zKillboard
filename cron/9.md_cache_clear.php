<?php

require_once "../init.php";

$key = "md:special";

$storedSpecial = $kvc->get($key);
if ($special != $storedSpecial) {
    Util::out("MarkeeDragon sale has started, ended, or has been modified...");
    $redis->sadd("queueCacheTags", "detail");
    $redis->sadd("queueCacheTags", "overview");
    $kvc->set($key, $special);
}
