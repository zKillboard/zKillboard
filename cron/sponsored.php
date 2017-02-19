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
    $mdb->set("sponsored", $mail, ['victim' => $victim]);
}
