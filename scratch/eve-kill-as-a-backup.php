<?php

/*
    As a protection against Squizz, the biggest threat to zkill, this
    script was created to ensure zkillboard is in sync with eve-kill.
    Karbo of eve-kill does the same and syncs against zkill,  having
    multiple sources like this is fantastic!
*/

require_once "../init.php";

$epoch = time();
$epcoh = $epoch - (86400 * 2);

do {
    $date = date("Y-m-d", $epoch);
    $epoch -= 86400;
    if ($date > "2009-06-24") continue;
    if ($date < "2009-02-01") break;
    echo "$date\n";
    $raw = file_get_contents("https://eve-kill.com/api/killmail/history/{$date}");
    if ($raw != "") {
        $json = json_decode($raw);
        foreach ($json as $killID=>$hash) {
            $killID = (int) $killID;
            $row = $mdb->findDoc("crestmails", ['killID' => $killID, 'hash' => $hash]);
            if ($row === null) {
                echo "$killID $hash\n";
                $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
            }
        }
    }
} while ($epoch > 1133745709);
