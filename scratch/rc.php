<?php

require_once "../init.php";

echo "$redisServer\n";
exit();

//clearKeys($redis, "RC:*");
//clearKeys($redis, "CacheKill:*");
clearKeys($redis, "run:*");

function clearKeys($redis, $keyBase) {
    $keys = $redis->keys($keyBase);
    foreach ($keys as $key) {
        $redis->del($key);
    }
    echo "Cleared " . sizeof($keys) . " total.\n";
}
