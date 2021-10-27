<?php

require_once "../init.php";

global $mdb;

$mails = $mdb->find("sponsored", ['victim' => ['$exists' => false]]);

foreach ($mails as $mail) {
    $killmail = $mdb->findDoc("killmails", ['killID' => $mail['killID']]);
    $involved = $killmail['involved'];
    $victim = array_shift($involved);
    unset($victim['isVictim']);
    $victim['solarSystemID'] = $killmail['system']['solarSystemID'];
    $victim['regionID'] = $killmail['system']['regionID'];
    $victim['itemID'] = @$killmail['zkb']['locationID'];
    if ($mail['isk'] >= 5000000 && $mdb->count("sponsored", ['victim.characterID' => $victim['characterID']]) == 0) {
        $kill = "https://zkillboard.com/kill/" . $mail['killID'] . "/";
        Util::sendsendEveMail($victim['characterID'], "Someone loves you!", "Someone has just sponsored one of your killmails on zKillboard:<br/><a href=\"$kill\">$kill</a><br/><br/>Cheers!");
    }
    $mdb->set("sponsored", $mail, ['victim' => $victim]);
}
