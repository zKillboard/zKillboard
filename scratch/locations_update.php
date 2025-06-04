<?php

require_once "../init.php";

$systems = $mdb->find("information", ['type' => 'solarSystemID']);

foreach ($systems as $system) {
    $solarSystemID = $system['id'];

    if ($solarSystemID > 32000000 && $solarSystemID <= 32999999) continue;

    Log::log("Fetching fuzz map for system $solarSystemID");
    $raw = file_get_contents("https://www.fuzzwork.co.uk/api/mapdata.php?solarsystemid=$solarSystemID&format=json");
    $systemLocations = json_decode($raw, true);
    $locations = $mdb->findDoc("locations", ['id' => $solarSystemID]);
    $locs = [];
    foreach ($locations['locations'] as $location) {
        $locs[$location['itemid']] = $location;
    }
    foreach ($systemLocations as $location) {
        $locs[$location['itemid']] = $location;
    }
    $systemLocations = array_values($locs);
    $save = ['id' => $solarSystemID, 'locations' => $systemLocations];
    $mdb->remove("locations", ['id' => $solarSystemID]);
    $mdb->save("locations", $save);
}
