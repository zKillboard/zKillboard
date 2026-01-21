<?php

require_once "../init.php";

if (date("i") % 15 === 0) {
    $multi = $redis->multi();
    $multi->sunionstore("queueCacheTags", "queueCacheTagsStatsTop");
    $multi->del("queueCacheTagsStatsTop");
    $multi->exec();
}

global $CF_ACCOUNT_ID, $CF_R2_ACCESS_KEY, $CF_R2_SECRET_KEY, $CF_R2_BUCKET;

$minute = date("Hi");
while (date("Hi") == $minute) {
    $tags = $redis->srandmember("queueCacheTags", 30);
    if (sizeof($tags)) {
        CloudFlare::purgeCacheTags($CF_ZONE_ID, $CF_API_TOKEN, $tags);
        $redis->srem("queueCacheTags", ...$tags);
    }
    sleep(1);
}
