<?php

require_once "../init.php";

$zEmail = "This week's winners are:<br/><br/>";

$count = 17;
while ($count > 0) {
    $r = $mdb->getCollection("rewards")->aggregate([['$sample' => ['size' => 1 ]]], ['cursor' => 1]);
    $resultArray = iterator_to_array($r);
    $winner = !empty($resultArray) ? $resultArray[0] : null;
    if ($winner === null || @$winner['winner'] === true) continue;
    $zEmail .= $winner['character_name'] . "<br/>";
    Util::out($winner['character_name'] . " : " . $winner['character_id']);
    $mdb->set("rewards", ['character_id' => $winner['character_id']], ['winner' => true, 'time' => $mdb->now()]);
    $count--;
}

Util::sendEveMail($adminCharacter, "Contest Winners", $zEmail);
