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
    $victimID = @$victim['characterID'] == 0 ? 'None' : $victim['characterID'];
    $shipTypeID = $victim['shipTypeID'];

    $attackers = $killmail['involved'];
    while ($next = array_shift($attackers)) {
        if (@$next['finalBlow'] == false) continue;
        $attacker = $next;
        break;
    }
    if ($attacker == null) $attacker = $attackers[0];
    $attackerID = @$attacker['characterID'];
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
