<?php

require_once "../init.php";

global $CF_ACCOUNT_ID, $CF_R2_ACCESS_KEY, $CF_R2_SECRET_KEY, $CF_R2_BUCKET;

if (!isset($CF_API_TOKEN) || !$CF_API_TOKEN || $CF_API_TOKEN === "") {
	$redis->del("queueCacheUrls");
	$redis->del("queueCacheUrlsDefer");
	exit();
}

$multi = $redis->multi();
$multi->sunionstore("queueCacheUrls", "queueCacheUrlsDefer");
$multi->del("queueCacheUrlsDefer");
$multi->exec();

$minute = date("Hi");
while (date("Hi") == $minute) {
    $urls = $redis->srandmember("queueCacheUrls", 25);
    if (sizeof($urls)) {
        try {
            sleep(1); // allow content to persist, such as R2
            CloudFlare::purgeUrls($CF_ZONE_ID, $CF_API_TOKEN, $urls);
            $redis->srem("queueCacheUrls", ...$urls);
        } catch (Exception $ex) {
            $redis->sadd("queueCacheUrls", ...$urls);
            Util::out("Cloudflare exception: " . $ex->getMessage());
            // CF goofed....
        }
    } else sleep(4);
}
