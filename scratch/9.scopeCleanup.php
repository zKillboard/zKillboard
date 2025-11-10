<?php

require_once "../init.php";

use cvweiss\redistools\RedisTimeQueue;

//if (date('Hi') != 1100) exit();

$threeMonths = time() - (86400 * 90);
$esiChar = new RedisTimeQueue('tqApiESI', 3600);

$mdb->set("scopes", ['added' => ['$exists' => false]], ['added' => $mdb->now()], true);

$rows = $mdb->find("scopes", ['scope' => "esi-killmails.read_killmails.v1" ]);
foreach($rows as $row) {
    $id = $row['characterID'];
    $added = $row['added']->sec;
    if ($id == $adminCharacter) continue; // don't remove admin

    if (($threeMonths - $added) <= 0) continue; // Has recent, move on

    $hasRecent = $mdb->exists("ninetyDays", ['involved.characterID' => $id]);
    if ($hasRecent) continue;

    // Does not have recent, and the row has been added more than 3 months ago, time to purge it
    //$mdb->remove("scopes", $row);
    Util::out("Removed $id from scopes");
}
exit();

$rows = $mdb->find("scopes", ['scope' => ['$ne' => "esi-killmails.read_killmails.v1"]]);
foreach ($rows as $row) {
    $id = $row['characterID'];
    if ($id == $adminCharacter) continue; // don't remove admin

    // Does this character have a killmail scope? If not, remove the scope
    $hasKmScope = $mdb->exists("scopes", ['characterID' => $id, 'scope' => "esi-killmails.read_killmails.v1"]);
    if ($hasKmScope === false) {
        $mdb->remove("scopes", $row);
    }
}
