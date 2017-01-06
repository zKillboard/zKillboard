<?php

require_once "../init.php";

$minutely = date('Hi');
while (date('Hi') == $minutely) {
    $uris = $redis->zrange("fetchSetCleanup", 0, 10, 1);
    foreach ($uris as $uri=>$time) {
        //echo (time() - $time) . " $uri\n";
        if ($time >= time()) break;
        $file = "$baseDir/public/cache${uri}index.html";
        unlink($file);
        $redis->zrem("fetchSetCleanup", $uri);        
        if ($uri == "/") $redis->zincrby("fetchSetSorted", 100, $uri);
    }
    sleep(1);
}
