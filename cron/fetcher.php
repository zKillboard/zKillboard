<?php

require_once "../init.php";

$bases = ['character', 'corporation', 'alliance', 'system', 'group', 'ship', 'faction', 'location', 'br', 'related', 'item', 'kills', 'war'];
$cacheRules = ['/^\/$/' => 60, "/^\/kill\/.*/" => 3600];
foreach ($bases as $base) {
    $rule = "/^\/$base\/.*/";
    $cacheRules[$rule] = 900;;
}

$minutely = date('Hi');
while ($minutely == date('Hi')) {
    $next = $redis->zRange("fetchSetSorted", -1, -1, true);

    if ($next !== false && sizeof($next) > 0) {
        $uri = array_keys($next);
        $uri = $uri[0];
        $score = array_values($next);
        $score = $score[0];
        $redis->zRem("fetchSetSorted", $uri);

        $redisKey = "fetch:$uri";
        $fetchKey = "fetch:$uri:fetched";
        $countKey = "fetch:$uri:count";
        if ($redis->get($fetchKey) != null) continue;
        if ($redis->get($redisKey) != null) continue;

        $multi = $redis->multi();
        $multi->setnx($countKey, 0);
        $multi->expire($countKey, 60);
        $multi->incr($countKey, $score);
        $multi->exec();

        $count = $redis->get($countKey);
        if ($count >= 5) {
            foreach ($cacheRules as $cacheRule => $ttl) {
                if (preg_match($cacheRule, $uri) == true) {

                    $url = "https://zkillboard.com" . $uri;

                    $result = getData($url, 0);
                    $code = $result['httpCode'];
                    if ($code == 200) {
                        //$redis->setex($redisKey, $ttl, $result['body']);
                        $redis->setex($fetchKey, 30, $code);
                        @mkdir("$baseDir/public/cache${uri}", 0777, true);
                        $file = "$baseDir/public/cache${uri}index.html";
                        $redis->zadd("fetchSetCleanup", time() + $ttl, $uri);
                        file_put_contents("$baseDir/public/cache${uri}index.html", $result['body']);
                    }
                }
            }
        }
        $redis->zRem("fetchSetSorted", $uri);
    } else {
        usleep(100000);
    }
}

function getData($url)
{
    global $baseAddr;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, "Cache Fetcher for https://$baseAddr");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); //timeout in seconds
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);


    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['httpCode' => $httpCode, 'body' => $body];
}
