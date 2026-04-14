<?php

$mt = 10; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

require_once '../init.php';

$uniqid = uniqid();
$c = $mdb->getCollection('zest3');
$tasks = $mdb->getCollection('zest3_tasks');

$time = time();
while (time() - $time < 60) {
    // prevent too much overrun 
    $lockKey = "zkb:zest3:kills:$mt";
    $gotLock = $redis->set($lockKey, 1, ['nx', 'ex' => 900]);
    if ($gotLock === false) {
        usleep(1000);
        continue;
    }

    $lockKeyNext = null;
    try {
        $doc = $tasks->findOneAndUpdate(
            ['mixed' => true],
            ['$set' => ['mixed' => 'processing', 'uniqid' => $uniqid]],
            [
                'projection' => ['type' => 1, 'id' => 1, 'sequence' => 1, '_id' => 0],
                'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]);
        if ($doc == null) {
            usleep(1000);
            continue;
        }

        $type = $doc['type'];
        $id = $doc['id'];
        $sequence = $doc['sequence'];
        $lockKeyNext = "zkb:zest3:kills:$type:$id";
        $gotLock = $redis->set($lockKeyNext, 1, ['nx', 'ex' => 900]);
        if ($gotLock === false) {
            $lockKeyNext = null;
            usleep(1000);
            continue;
        }

        $cacheTag = str_replace("shipType", "ship", str_replace("solarS", "s", str_replace("ID", "", "$type:$id")));

        try {
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
            $match = ['type' => $doc['type'], 'id' => $doc['id'], 'sequence' => $doc['sequence']];
            $set = ['$set' => ['mixed' => false]];
            $r = $tasks->updateOne($match, $set);

            $redis->sadd("queueCacheTagsDefer", "killlist:$cacheTag");
        } catch (Exception $ex) {
            Util::out(print_r($ex, true));
        }
    } finally {
        $redis->del($lockKey);
        if ($lockKeyNext !== null) $redis->del($lockKeyNext);
    }
}
// cleanup
$r = $tasks->updateMany(['mixed' => 'processing', 'uniqid' => $uniqid], ['$set' => ['mixed' => true]]);
$r = $tasks->updateMany(['uniqid' => $uniqid], ['$unset' => ['uniqid' => 1]]);

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
