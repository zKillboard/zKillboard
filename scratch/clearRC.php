<?php

require_once "../init.php";

//clearKeys($redis, "RC:*");
//clearKeys($redis, "backup:*");
//clearKeys($redis, "info:*");
//clearKeys($redis, "kill*");
//clearKeys($redis, "br*");
clearKeys($redis, "killmail_cache*");

function clearKeys($redis, $keyBase) {
    $keys = $redis->keys($keyBase);
    foreach ($keys as $key) {
        $redis->del($key);
    }
    echo "Cleared " . sizeof($keys) . " total.\n";
}
