<?php

require_once "../init.php";

if ($redis->get("zkb:monocled") == "true") exit();

$iter = $mdb->getCollection("payments")->distinct("characterID");
foreach ($iter as $id) {
    $userInfo = $mdb->findDoc("users", ['userID' => "user:$id"]);
    if (@$userInfo['monocle'] != true) {
        $result = Mdb::group("payments", ['characterID'], ['characterID' => (int) $id], [], 'isk', ['iskSum' => -1], 6);
        $isk = $result[0]['iskSum'];
        if ($isk >= 1000000000) {
            Util::out("$id monocled $isk");
            $mdb->set("users", ['characterID' => (int) $id], ['monocle' => true]);

            EveMail::send($id, "Monocle!", "You have given at least 1000000000 ISK to zKillboard! In appreciation of your deep pockets a monocle will show up very soon on your character's zKillboard page. Thank you! \n\n<a href=\"https://zkillboard.com/character/$id/\">Your zKillboard character page.</a>");
            sleep(1);
        }   
    }
}


$redis->setex("zkb:monocled", 7200, "true");
