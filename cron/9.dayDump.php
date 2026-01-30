<?php

require_once "../init.php";

$day = date('Ymd', time() - (6 * 3600));
$key = "zkb:dayDumpR2Z2:$day";
if ($kvc->get($key) == "true") exit();
if (@$CF_R2_SEND != gethostname()) exit(); // prevents dev servers from overwriting

global $CF_ACCOUNT_ID, $CF_R2_ACCESS_KEY, $CF_R2_SECRET_KEY, $CF_R2_BUCKET;
if (!$CF_ACCOUNT_ID || !$CF_R2_ACCESS_KEY || !$CF_R2_SECRET_KEY || !$CF_R2_BUCKET) {
	exit();
}

Util::out("Populating dayDumps");

$r2 = CloudFlare::getR2Client(
	$CF_ACCOUNT_ID,
	$CF_R2_ACCESS_KEY,
	$CF_R2_SECRET_KEY
);

$totals = [];
$hashes = [];
$curDate = "";

$collection = $mdb->getCollection("killmails");
$cursor = $collection->find([], [
    'projection' => [
        'killID' => 1,
        'dttm' => 1,
        'zkb.hash' => 1,
        '_id' => 0,
    ],
    'sort' => ['killID' => 1],
    'noCursorTimeout' => true
]);

$options = [
    'ACL' => 'public-read',
    'CacheControl' => 'public, max-age=31536000',
    'ContentType'  => 'application/json'
];

foreach ($cursor as $row) {
    $time = $row['dttm']->toDateTime()->getTimestamp();
    $time = $time - ($time % 86400);
    $date = date('Ymd', $time);
    if ($date != $curDate) {
        if (sizeof($hashes)) {
            $md5 = md5(implode("", $hashes));
            if ($kvc->get("zkb:dayDumpHash:$curDate") != $md5) {
                Util::out("Iterated - $curDate - sending");
                CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $hashes, "history/$curDate.json", $options);
                $kvc->set("zkb:dayDumpHash:$curDate", $md5, 99999 * 86400);

                $redis->sadd("queueCacheUrls", "https://r2z2.zkillboard.com/history/$curDate.json");
            } else Util::out("Iterated - $curDate");
            $totals[$curDate] = sizeof($hashes);
            $minKillID = min(array_keys($hashes));
            $redis->setex("zkb:firstkillid:$curDate", 7 * 86400, $minKillID);
            $kvc->set("zkb:firstkillid:$curDate", $minKillID, (7 * 86400));
        }

        $hashes = [];
        $curDate = $date;
        if ($curDate == $day) break;
    }

    $killID = (int) $row['killID'];
    $hash = trim($row['zkb']['hash']);
    if ($killID < 0 || $hash == "") { echo "Skipping $killID ($hash)\n"; continue; }

    $hashes[$killID] = $hash;
}
CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $totals, "history/totals.json", $options);
$redis->sadd("queueCacheUrls", "https://r2z2.zkillboard.com/history/totals.json");

$kvc->setex($key, 86400, "true");

