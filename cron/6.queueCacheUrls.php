<?php

require_once "../init.php";

global $CF_ACCOUNT_ID, $CF_R2_ACCESS_KEY, $CF_R2_SECRET_KEY, $CF_R2_BUCKET;

$minute = date("Hi");
while (date("Hi") == $minute) {
    $urls = $redis->srandmember("queueCacheUrls", 30);
    if (sizeof($urls)) {
        try {
            CloudFlare::purgeUrls($CF_ZONE_ID, $CF_API_TOKEN, $urls);
            $redis->srem("queueCacheUrls", ...$urls);
        } catch (Exception $ex) {
            Util::out("Cloudflare exception: " . $ex->getMessage());
            // CF goofed....
        }
    }
    sleep(1);
}
