<?php

require_once "../init.php";

if (date("i") % 15 === 0) {
    $multi = $redis->multi();
    $multi->sunionstore("queueCacheTags", "queueCacheTagsStatsTop");
    $multi->del("queueCacheTagsStatsTop");
    $multi->exec();
}

global $CF_ACCOUNT_ID, $CF_R2_ACCESS_KEY, $CF_R2_SECRET_KEY, $CF_R2_BUCKET;

if (!isset($CF_API_TOKEN) || !$CF_API_TOKEN || $CF_API_TOKEN === "") {
	$redis->del("queueCacheTags");
	exit();
}

$lastDeferMove = 0;
$minute = date("Hi");
while (date("Hi") == $minute) {
    if (time() - $lastDeferMove > 5) {
        $redis->multi();
        $redis->sUnionStore('queueCacheTags', 'queueCacheTags', 'queueCacheTagsDefer');
        $redis->del('queueCacheTagsDefer');
        $result = $redis->exec();
        $lastDeferMove = time();
    }
    $tags = $redis->srandmember("queueCacheTags", 25);
    if (sizeof($tags)) {
        try {
            CloudFlare::purgeCacheTags($CF_ZONE_ID, $CF_API_TOKEN, $tags);
            $redis->srem("queueCacheTags", ...$tags);
        } catch (Exception $ex) {
            $redis->sadd("queueCacheTags", ...$tags);
            Util::out("Cloudflare exception: " . $ex->getMessage());
            // CF goofed....
        }
    }
    usleep(250000);
}
