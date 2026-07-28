<?php

use MongoDB\BSON\ObjectId;

require_once '../init.php';

$limit = 10;
$processed = 0;
$updated = 0;
$cursorKey = 'zkb:campaign-stats:last-id';
$lastID = (string) $redis->get($cursorKey);

for ($pass = 0; $pass < 2 && $processed < $limit; $pass++) {
    $query = [];
    if ($lastID != '') {
        try {
            $query['_id'] = ['$gt' => new ObjectId($lastID)];
        } catch (Exception $ex) {
            $lastID = '';
        }
    }

    $campaigns = $mdb->getCollection('campaigns')->find($query, ['sort' => ['_id' => 1], 'limit' => $limit - $processed]);
    $found = 0;
    foreach ($campaigns as $campaign) {
        if ($redis->get('zkb:reinforced') == true) break 2;

        $uid = (string) ($campaign['_id'] ?? '');
        if ($uid == '') continue;

        $lastID = $uid;
        $redis->set($cursorKey, $lastID);
        $found++;
        $processed++;
        if (Campaign::updateStoredStats($campaign)) $updated++;
    }

    if ($found > 0 || $lastID == '') break;
    $lastID = '';
    $redis->del($cursorKey);
}

if ($updated > 0) Util::out("campaignStats updated $updated of $processed campaigns");
