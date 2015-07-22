<?php

require_once '../init.php';

$i = date('i');
if ($i % 15 != 0) {
    exit();
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

function getStats($column)
{
    $result = Stats::getTop($column, ['isVictim' => false, 'pastSeconds' => 604800]);

    return $result;
}
