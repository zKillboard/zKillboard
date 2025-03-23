<?php

require_once "../init.php";

if ($redis->get("zkb:dqcheck") == "true") exit();

$mdb->set('information', ['type' => 'corporationID', 'disqualified' => true], ['dqremove' => true], true);
$mdb->set('information', ['type' => 'allianceID', 'disqualified' => true], ['dqremove' => true], true);

$dqed = $mdb->find('information', ['type' => 'characterID', 'disqualified' => true]);
foreach ($dqed as $dq) {
    //Util::out("Character is dq: " . $dq['id']);

    if (@$dq['corporationID'] > 1999999) {
        $mdb->set('information', ['type' => 'corporationID', 'id' => $dq['corporationID']], ['disqualified' => true, 'dqremove' => false]);
        //Util::out("Corporation is dq: " . $dq['corporationID']);
    }

    if (@$dq['allianceID'] > 0) {
        $mdb->set('information', ['type' => 'allianceID', 'id' => $dq['allianceID']], ['disqualified' => true, 'dqremove' => false]);
        //Util::out("Alliance is dq: " . $dq['allianceID']);
    }
}

$mdb->set('information', ['dqremove' => true], ['disqualified' => false], true);
$mdb->removeField('information', ['dqremove' => true], 'disqualified', true);
$mdb->removeField('information', ['dqremove' => ['$exists' => true]], 'dqremove', true);

$redis->setex("zkb:dqcheck", 3601, "true");
