<?php

require_once "../init.php";

if ($redis->get("tobefetched") > 1000) exit();

if (date('Hi') < "1100") exit();
$key = "zkb:calcedTrophies:" . date("Ymd");
if ($redis->get("$key") == "true") exit();

$minute = Date('Hi');
while ($minute == date('Hi')) {
    if ($redis->get("zkb:reinforced") == true) exit();
    $row = $mdb->findDoc("statistics", ['calcTrophies' => true]);
    if ($row == null) break;
    $charID = $row['id'];
    $trophies = Trophies::getTrophies($charID);

    $t = ['levels' => $trophies['levelCount'], 'max' => $trophies['maxLevelCount']];
    $mdb->set("statistics", $row, ['trophies' => $t]);
    $mdb->removeField("statistics", $row, 'calcTrophies');
}

$count = $mdb->count("statistics", ['calcTrophies' => true]);
if ($count == 0) $redis->setex($key, 86400, "true");
