<?php

require_once "../init.php";

$bases = ['character', 'corporation', 'alliance', 'system', 'group', 'ship', 'faction', 'location', 'br', 'related', 'item', 'kills', 'war'];
$cacheRules = ['/^\/$/' => 120, "/^\/kill\/.*/" => 3600];
foreach ($bases as $base) {
    $rule = "/^\/$base\/.*/";
    $cacheRules[$rule] = 900;;
}

$minutely = date('Hi');
while ($minutely == date('Hi')) {
    $uri = $redis->lpop("fetchSet");
    if ($uri !== false) {
        foreach ($cacheRules as $cacheRule => $ttl) {
            if (preg_match($cacheRule, $uri) == true) {
                $redisKey = "fetch:$uri";
                $countKey = "fetch:$uri:count";
                $fetchKey = "fetch:$uri:fetched";
                if ($redis->get($redisKey) != null) continue;
                if ($redis->get($fetchKey) != null) continue;

                $multi = $redis->multi();
                $multi->setnx($countKey, 0);
                $multi->expire($countKey, 60);
                $multi->incr($countKey);
                $multi->exec();

                $count = $redis->get($countKey);
                if ($count >= 3) {
                    $url = "https://zkillboard.com" . $uri;

                    $result = getData($url, 0);
                    $code = $result['httpCode'];
                    if ($code == 200) {
                        $redis->setex($redisKey, $ttl, $result['body']);
                        //Util::out("$ttl $uri");
                    } else Util::out("Error $code for $uri");
                    $redis->setex($fetchKey, 30, $code);
                    $redis->lrem("fetchSet", $uri, 0);
                }
            }
        }
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
