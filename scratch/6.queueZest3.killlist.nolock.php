<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

require_once '../init.php';

$c = $mdb->getCollection('zest3');
$tasks = $mdb->getCollection('zest3_tasks');
$lockKey = "zkb:zest3:killlist:$mt";

$minute = date("Hi");
while (date("Hi") == $minute) {
    if ($redis->set($lockKey, 1, ['nx', 'ex' => 900])) {
        try {
            $doc = null;
            $lockKeyNext = null;
            $docs = $tasks->find(['mixed' => ['$ne' => false]]);

            foreach ($docs as $doc) {
                $type = $doc['type'];
                $id = $doc['id'];
                $lockKeyNext = "zkb:zest3:killlist:$type:$id";
                if ($redis->set($lockKeyNext, 1, ['nx', 'ex' => 900])) {
                    try {
                        // Re-check the latest mixed after acquiring lock so stale cursor rows are skipped.
                        $current = $tasks->findOne(['type' => $type, 'id' => $id], ['projection' => ['mixed' => 1]]);
                        if ($current !== null && ($current['mixed'] ?? false) === $doc['mixed']) {
                            process($type, $id, $doc, $c, $tasks);
                        }
                    } finally {
                        $redis->del($lockKeyNext);
                    }
                    if (date("Hi") != $minute) break;
                }
            }
            if ($doc === null) usleep(100000);
        } finally {
            $redis->del($lockKey);
        }
    } else usleep(100000);
}


function process($type, $id, $doc, $c, $tasks) {
    $cacheTag = str_replace("shipType", "ship", str_replace("solarS", "s", str_replace("ID", "", "$type:$id")));

    if ($id !== 0) {
        $type = str_replace("ID", "", $type);
        switch ($type) {
            case 'character':
            case 'corporation':
            case 'alliance':
            case 'faction':
            case 'group':
            case 'ship':
            case 'label':
            case 'location':
            case 'system':
            case 'constellation':
            case 'region':
                break;
            case 'solarSystem':
                $type = 'system';
                break;
            case 'shipType':
                $type = 'ship';
                break;
            default:
                exit("Unknown type: $type");
        }
        $ops = [
            buildOp("/$type/$id/",          "/$type/$id/mixed.json", $cacheTag, $doc['type']),
        ];
        $ops[] = buildOp($type == "label" ? null : "/$type/$id/solo/",     "/$type/$id/solo.json", $cacheTag, $doc['type']);
        switch ($type) {
            case 'character':
            case 'corporation':
            case 'alliance':
            case 'faction':
            case 'ship':
            case 'group':
                $ops[] = buildOp("/$type/$id/kills/",    "/$type/$id/kills.json", $cacheTag, $doc['type']);
                $ops[] = buildOp("/$type/$id/losses/",   "/$type/$id/losses.json", $cacheTag, $doc['type']);
                break;
            default: 
                $ops[] = buildOp(null,    "/$type/$id/kills.json", $cacheTag, $doc['type']);
                $ops[] = buildOp(null,    "/$type/$id/losses.json", $cacheTag, $doc['type']);
        }
        $c->bulkWrite($ops);
    }
    $match = ['type' => $doc['type'], 'id' => $doc['id'], 'mixed' => $doc['mixed']];
    $set = ['$set' => ['mixed' => false]];
    $r = $tasks->updateOne($match, $set);
    $m = $r->getModifiedCount();
    //if ($m > 0) $redis->sadd("queueCacheTagsDefer", "killlist:$cacheTag");
}

function buildOp($url, $path, $cacheTag, $type)
{
    $arr = $url === null ? [] : transform($url);
    return ['updateOne' => [
        ['path' => $path],
        [
            '$set' => [
                'path' => $path,
        'content' => json_encode($arr, true),
        'mimetype' => 'application/json',
        'maxage' => 1,
        'smaxage' => 31000000,
        'lastModified' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
        'headers' => [
            'Cache-Tag' => "zest3,killlist,killlist:$cacheTag",
        'Access-Control-Allow-Origin' => 'https://zkillboard.com'
        ],
            ],
        ],
        ['upsert' => true],
    ]];
}

function transform($url)
{
    $p = Util::convertUriToParameters($url);
    $p['limit'] = 50;
    $kills = Kills::getKills($p, true, false, false);
    $arr = [];
    foreach ($kills as $kill) {
        $arr[] = $kill['killID'];
    }
    return $arr;
}
