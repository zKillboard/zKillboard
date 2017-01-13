<?php

require_once "../init.php";

$characters = $mdb->find("statistics", ['calcTrophies' => true], [], null, ['id' => 1]);

foreach ($characters as $char) {
    $charID = (int) $char['id'];
    $trophies = Trophies::getTrophies($charID);

    $t = ['levels' => $trophies['levelCount'], 'max' => $trophies['maxLevelCount']];
    $mdb->set("statistics", ['type' => 'characterID', 'id' => $charID], ['trophies' => $t, 'calcTrophies' => false]);
}
