<?php

use MongoDB\Driver\BulkWrite;

require_once "../init.php";

$cursor = $mdb->getCollection("killmails")->find(['labels' => 'pvp'], ['sort' => ['killID' => -1]]);

$updated = 0;
$count   = 0;

$bulk       = new BulkWrite(['ordered' => true]);
$bulkCount  = 0;
$namespace  = $mdb->getCollection("killmails");

foreach ($cursor as $row) {
    $padhash = getPadHash($row);

    if (@$row['padhash'] !== $padhash) {
        $bulk->update(
            ['killID' => $row['killID']],
            ['$set' => ['padhash' => $padhash]],
            ['multi' => false, 'upsert' => false]
        );

        $bulkCount++;
        $updated++;
    }

    $count++;

    if ($bulkCount === 1000) {
        $mdb->getDb()->getManager()->executeBulkWrite($namespace, $bulk);
        $bulk = new BulkWrite(['ordered' => true]);
        $bulkCount = 0;
    }

    if ($count % 10000 === 0) {
        Util::out("Updated: $updated\t\tIterated: $count");
    }
}

if ($bulkCount > 0) {
    $mdb->getDb()->getManager()->executeBulkWrite($namespace, $bulk);
}

Util::out("Updated: $updated\t\tIterated: $count");


function getPadHash($killmail)
{
    global $mdb;

    if ($killmail['npc'] == true) return;

    $victim = array_shift($killmail['involved']);
    $victimID = (int) @$victim['characterID'] == 0 ? 'None' : $victim['characterID'];
    if ($victimID == 0) return;
    $shipTypeID = (int) $victim['shipTypeID'];
    if ($shipTypeID == 0) return;
    $groupID = (int) Info::getInfoField('groupID', $victim['groupID'], 'groupID');
    $categoryID = (int) Info::getInfoField('groupID', $victim['groupID'], 'categoryID');
    if ($categoryID != 6) return; // Only ships, ignore POS modules, etc.
    if ($victimID == "None") return "unpiloted";

    if ($groupID == 31) { // Shuttles
        $dttm = $killmail['dttm']->toDateTime()->getTimestamp();
        $dttm = $dttm - ($dttm % 3600);
        $locationID = isset($killmail['locationID']) ? $killmail['locationID'] : $killmail['system']['solarSystemID'];

        return "$victimID:$groupID:$locationID:$dttm";
    }

    $attackers = $killmail['involved'];
    while ($next = array_shift($attackers)) {
        if (@$next['finalBlow'] == false) continue;
        $attacker = $next;
        break;
    }
    if ($attacker == null) $attacker = $attackers[0];
    $attackerID = (int) @$attacker['characterID'];

    $dttm = $killmail['dttm']->toDateTime()->getTimestamp();
    $dttm = $dttm - ($dttm % 900);

    return "$victimID:$attackerID:$shipTypeID:$dttm";
}
