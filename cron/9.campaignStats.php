<?php

use MongoDB\BSON\ObjectId;

require_once '../init.php';

$limit = 10;
$processed = 0;
$updated = 0;
$cursorKey = 'zkb:campaign-stats:last-id';
$pauseKey = 'zkb:campaign-stats:pause';
if ($redis->get($pauseKey) == 'true') exit();

$lastID = (string) $redis->get($cursorKey);
$now = gmdate('Y-m-d\TH:i');
$query = [
    '$or' => [
        ['stats' => ['$exists' => false]],
        ['filters.dtend' => ['$gte' => $now]],
    ],
];

if ($lastID != '') {
    try {
        $query['_id'] = ['$gt' => new ObjectId($lastID)];
    } catch (Exception $ex) {
        $lastID = '';
    }
}

$campaigns = $mdb->getCollection('campaigns')->find($query, ['sort' => ['_id' => 1], 'limit' => $limit]);
foreach ($campaigns as $campaign) {
    if ($redis->get('zkb:reinforced') == true) break;

    $uid = (string) ($campaign['_id'] ?? '');
    if ($uid == '') continue;

    $lastID = $uid;
    $redis->set($cursorKey, $lastID);
    $processed++;
    if (Campaign::updateStoredStats($campaign)) $updated++;
}

if ($processed < $limit) {
    $redis->del($cursorKey);
    $redis->setex($pauseKey, Campaign::RESULT_CACHE_SECONDS, 'true');
}

if ($updated > 0) Util::out("campaignStats updated $updated of $processed campaigns");
