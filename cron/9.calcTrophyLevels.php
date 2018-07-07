<?php

require_once "../init.php";

if (date('i') != 33) exit(); // Only do calcs once an hour

while (true) {
    $row = $mdb->findDoc("statistics", ['calcTrophies' => true]);
    if ($row == null) break;
    $charID = $row['id'];
    $trophies = Trophies::getTrophies($charID);

    $t = ['levels' => $trophies['levelCount'], 'max' => $trophies['maxLevelCount']];
    $mdb->set("statistics", $row, ['trophies' => $t]);
    $mdb->removeField("statistics", $row, 'calcTrophies');
}

