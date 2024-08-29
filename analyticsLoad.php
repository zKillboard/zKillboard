<?php

if (strpos($uri, "/cache/") === false) {
    $agent = @$_SERVER['HTTP_USER_AGENT'];
    $agent = "$ip $agent";
    $ts = floor(time() / 60);
    $multi = $redis;
    $multi->hIncrBy("analytics:ip:$ts", $ip, 1);
    $multi->expire("analytics:ip:$ts", 300);
    $multi->hIncrBy("analytics:agent:$ts", $agent, 1);
    $multi->expire("analytics:agent:$ts", 300);
    $multi->hIncrBy("analytics:uri:$ts", $uri, 1);
    $multi->expire("analytics:uri:$ts", 300);
    $multi->exec();
}
