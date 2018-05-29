<?php

require_once "../init.php";

$characters = $mdb->find("statistics", ['calcTrophies' => true], [], 10000, ['id' => 1]);

$minute = date('Hi');
foreach ($characters as $char) {
    if ($minute != date('Hi')) break;
    $charID = (int) $char['id'];
    $trophies = Trophies::getTrophies($charID);

    $t = ['levels' => $trophies['levelCount'], 'max' => $trophies['maxLevelCount']];
    $mdb->set("statistics", ['type' => 'characterID', 'id' => $charID], ['trophies' => $t, 'calcTrophies' => false]);
}
