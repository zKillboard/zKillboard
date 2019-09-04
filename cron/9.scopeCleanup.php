<?php

require_once "../init.php";

if (date('i') != 0) exit();

$rows = $mdb->find("scopes", ['scope' => 'esi-fittings.write_fittings.v1']);
foreach ($rows as $row) {
    $charID = $row['characterID'];
    $kmRow = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => 'esi-killmails.read_killmails.v1']);
    if ($kmRow == null) {
        Util::out("Removing scope " . $row['scope'] . " for $charID which has no killmail scope.");
        $mdb->remove("scopes", $row);
    }
}
