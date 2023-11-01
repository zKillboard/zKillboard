<?php

require_once "../init.php";

$day = date('Ymd', time() - (11 * 3600));
$key = "zkb:dayDump:$day";
if ($redis->get($key) == "true") exit();

Util::out("Populating dayDumps");

$totals = [];
$hashes = [];
$md = "";
$curDate = "";

$cursor = $mdb->getCollection("killmails")->find([], ['dttm' => 1, 'killID' => 1, 'zkb.hash' => 1, '_id' => 0])->sort(['killID' => 1]);
foreach ($cursor as $row) {
    $time = $row['dttm']->sec;
    $time = $time - ($time % 86400);
    $date = date('Ymd', $time);
    if ($date != $curDate) {
        $md5 = md5($md);
        $write = "";
        if ($redis->get("zk:daydump:$date") != $md5) {
            foreach ($hashes as $wdate => $dayHashes) {
                file_put_contents("./public/api/history/$wdate.json", json_encode($dayHashes)); 
                $redis->set("zkb:firstkillid:$wdate", min(array_keys($dayHashes)));
                $totals[$wdate] = count($dayHashes);
                $write = " (w)";
                Util::out("Day dumping $wdate $md5");
            }
            $redis->setex("zk:daydump:$date", 200000, $md5);
        }
        $curDate = $date;
        $hashes = [];
        $md = "";
    }

    $killID = (int) $row['killID'];
    $hash = trim($row['zkb']['hash']);
    if ($killID <= 0 || $hash == "") { echo "Skipping $killID ($hash)\n"; continue; }
    $md = "$md:$killID:$hash:";

    $hashes[$date][$killID] = $hash;
}

foreach ($hashes as $date => $dayHashes) {
    file_put_contents("./public/api/history/$date.json", json_encode($dayHashes)); 
    $redis->set("zkb:firstkillid:$date", min(array_keys($dayHashes)));
    $totals[$date] = count($dayHashes);
}
file_put_contents("./public/api/history/totals.json", json_encode($totals));

$redis->setex($key, 86400, "true");
