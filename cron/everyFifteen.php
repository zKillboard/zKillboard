<?php

require_once '../init.php';

if (Util::isMaintenanceMode()) {
    return;
}
$minute = date('i');
if (!in_array('-f', $argv) && $minute != 15) {
    return;
}

$p = array();
$numDays = 7;
$p['limit'] = 10;
$p['pastSeconds'] = $numDays * 86400;
$p['kills'] = true;

Storage::store('Kills5b+', json_encode(Kills::getKills(array('iskValue' => 5000000000), true, false)));
Storage::store('Kills10b+', json_encode(Kills::getKills(array('iskValue' => 10000000000), true, false)));

Storage::store('TopChars', json_encode(Info::doMakeCommon('Top Characters', 'characterID', getStats('characterID'))));
Storage::store('TopCorps', json_encode(Info::doMakeCommon('Top Corporations', 'corporationID', getStats('corporationID'))));
Storage::store('TopAllis', json_encode(Info::doMakeCommon('Top Alliances', 'allianceID', getStats('allianceID'))));
Storage::store('TopShips', json_encode(Info::doMakeCommon('Top Ships', 'shipTypeID', getStats('shipTypeID'))));
Storage::store('TopSystems', json_encode(Info::doMakeCommon('Top Systems', 'solarSystemID', getStats('solarSystemID'))));
Storage::store('TopIsk', json_encode(Stats::getTopIsk(array('pastSeconds' => ($numDays * 86400), 'limit' => 5))));
Storage::store('TopPods', json_encode(Stats::getTopIsk(array('groupID' => 29, 'pastSeconds' => ($numDays * 86400), 'limit' => 5))));
Storage::store('TopPoints', json_encode(Stats::getTopPoints('killID', array('losses' => true, 'pastSeconds' => ($numDays * 86400), 'limit' => 5))));

function getStats($column)
{
    $result = Stats::getTop($column, ['isVictim' => false, 'pastSeconds' => 604800]);

    return $result;
}
