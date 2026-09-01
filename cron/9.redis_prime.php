<?php

require_once __DIR__ . '/../init.php';

if ($redis->get("zkb:entities_primed") == "true") exit();

$entities = [
    'characterID' => 'involved.characterID',
    'corporationID' => 'involved.corporationID',
    'allianceID' => 'involved.allianceID',
    'factionID' => 'involved.factionID',
    'shipTypeID' => 'involved.shipTypeID',
    'groupID' => 'involved.groupID',
    'locationID' => 'locationID',
    'solarSystemID' => 'system.solarSystemID',
    'constellationID' => 'system.constellationID',
    'regionID' => 'system.regionID',
];

foreach ($entities as $type => $field) {
    $count = 0;
    $ids = $mdb->getCollection('ninetyDays')->distinct($field);
	Util::out("Caching " . sizeof($ids) . " recent $type entities");

    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 1) continue;

        $info = Info::loadIntoRedis($type, $id, 3600);
        $count++;
    }

    Util::out("Cached $count recent $type entities");
}

$redis->setex("zkb:entities_primed", 3500, "true");
