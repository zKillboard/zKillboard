<?php

$mt = 6; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

require_once '../init.php';

$uniqid = uniqid();
$c = $mdb->getCollection('zest3');
$stats = $mdb->getCollection('statistics');

$time = time();
while (time() - $time < 70) {
    // prevent too much overrun
    $lockKey = "zkb:zest3:kills:$mt";
    $gotLock = $redis->set($lockKey, 1, ['nx', 'ex' => 900]);
    if ($gotLock == false) {
        usleep(1000);
        continue;
    }

    try {
        $doc = $stats->findOneAndUpdate(
            ['zest3' => true],
            ['$set' => ['zest3' => 'processing', 'uniqid' => $uniqid]],
            [
                'sort' => ['_id' => -1],
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
        $next = "$type:$id";

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
                    buildOp("/$type/$id/",          "/$type/$id/mixed.json", $next, $doc['type']),
                ];
                $ops[] = buildOp($type == "label" ? null : "/$type/$id/solo/",     "/$type/$id/solo.json", $next, $doc['type']);
                switch ($type) {
                    case 'character':
                    case 'corporation':
                    case 'alliance':
                    case 'faction':
                    case 'ship':
                    case 'group':
                        $ops[] = buildOp("/$type/$id/kills/",    "/$type/$id/kills.json", $next, $doc['type']);
                        $ops[] = buildOp("/$type/$id/losses/",   "/$type/$id/losses.json", $next, $doc['type']);
                        break;
                    default:
                        $ops[] = buildOp(null,    "/$type/$id/kills.json", $next, $doc['type']);
                        $ops[] = buildOp(null,    "/$type/$id/losses.json", $next, $doc['type']);
                }
                $c->bulkWrite($ops);
            }
            $match = ['type' => $doc['type'], 'id' => $doc['id'], 'sequence' => $doc['sequence']];
            $set = ['$set' => ['zest3' => false]];
            $stats->updateOne($match, $set);
        } catch (Exception $ex) {
            Util::out(print_r($ex, true));
        }
    } finally {
        $redis->del($lockKey);
    }
}
// cleanup
$stats->updateMany(['zest3' => 'processing', 'uniqid' => $uniqid], ['$set' => ['zest3' => true]]);

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
                'maxage' => 60,
                'smaxage' => 3600,
                'lastModified' => new MongoDB\BSON\UTCDateTime((int) (microtime(true) * 1000)),
                'headers' => [
                    'Cache-Tag' => "zest3,$cacheTag",
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