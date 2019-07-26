<?php

require_once "../init.php";

$minute = date('Hi');
while ($minute == date('Hi') && ($killID = (int) $redis->spop("padhash_ids"))) {
    $killmail = $mdb->findDoc("killmails", ['killID' => $killID]);
    doPadHash($killID, $killmail);
}

// https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
function doPadHash($killID, $killmail)
{
    global $mdb;

    $victim = array_shift($killmail['involved']);
    $victimID = (int) @$victim['characterID'] == 0 ? 'None' : $victim['characterID'];
    if ($victimID == 0) return;
    $shipTypeID = (int) $victim['shipTypeID'];
    if ($shipTypeID == 0) return;
    $categoryID = (int) Info::getInfoField('groupID', $victim['groupID'], 'categoryID');
    if ($categoryID != 6) return; // Only ships, ignore POS modules, etc.

    $attackers = $killmail['involved'];
    while ($next = array_shift($attackers)) {
        if (@$next['finalBlow'] == false) continue;
        $attacker = $next;
        break;
    }
    if ($attacker == null) $attacker = $attackers[0];
    $attackerID = (int) @$attacker['characterID'];
    if ($attackerID == 0) return;

    $dttm = $killmail['dttm']->sec;
    $dttm = $dttm - ($dttm % 86400);

    $aString = "$victimID:$attackerID:$shipTypeID:$dttm";
    $aSha = sha1($aString);
    $mdb->getCollection("padhash")->update(['characterID' => $attackerID, 'isVictim' => false, 'hash' => $aSha], ['$inc' => ['count' => 1]], ['upsert' => true]);

    $vString = "$attackerID:$victimID:$shipTypeID:$dttm";
    $vSha = sha1($vString);
    $mdb->getCollection("padhash")->update(['characterID' => $victimID, 'isVictim' => true, 'hash' => $vSha], ['$inc' => ['count' => 1]], ['upsert' => true]);
}
