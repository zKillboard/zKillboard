<?php

require_once "../init.php";

$kvc = new KVCache($mdb, $redis);

$day = date('Ymd', time() - (11 * 3600));
$key = "zkb:dayDump:$day";
if ($kvc->get($key) == "true") exit();

Util::out("Populating dayDumps");

$totals = [];
$hashes = [];
$curDate = "";

$collection = $mdb->getCollection("killmails");
$cursor = $collection->find([], [
    'sort' => ['killID' => 1],
    'noCursorTimeout' => true
]);
foreach ($cursor as $row) {
    $time = $row['dttm']->toDateTime()->getTimestamp();
    $time = $time - ($time % 86400);
    $date = date('Ymd', $time);
    if ($date != $curDate) {
        $curDate = $date;
        Util::out("Day dumping $date");
    }

    $killID = (int) $row['killID'];
    $hash = trim($row['zkb']['hash']);
    if ($killID < 0 || $hash == "") { echo "Skipping $killID ($hash)\n"; continue; }
 
    $hashes[$date][$killID] = $hash;
}

foreach ($hashes as $date => $dayHashes) {
    file_put_contents("./public/api/history/$date.json", json_encode($dayHashes)); 
    $redis->set("zkb:firstkillid:$date", min(array_keys($dayHashes)));
    $kvc->set("zkb:firstkillid:$date", min(array_keys($dayHashes)));
    $totals[$date] = count($dayHashes);
}
file_put_contents("./public/api/history/totals.json", json_encode($totals));

$kvc->setex($key, 86400, "true");
