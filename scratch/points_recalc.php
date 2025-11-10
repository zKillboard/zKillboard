<?php

$mt = 14; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once "../init.php";

$delta = 10000000;
$min = $delta * $mt;
$max = $min + $delta;
$cursor = $mdb->getCollection("killmails")->find(['killID' => ['$gte' => $min, '$lt' => $max]], ['sort' => ['killID' => -1]]);
foreach ($cursor as $km) {
    $points = Points::getKillPoints($km['killID']);
    if ($km['zkb']['points'] != $points) {
        //$mdb->set("oneWeek", ['killID' => $km['killID']], ['zkb.points' => $points]);
        //$mdb->set("ninetyDays", ['killID' => $km['killID']], ['zkb.points' => $points]);
        $mdb->set("killmails", ['killID' => $km['killID']], ['zkb.points' => $points]);
        $diff = $points - $km['zkb']['points'];
        if ($diff > 0) $diff = "+" . $diff;
        echo $km['killID'] . " $points ($diff)\n";
    }
}
