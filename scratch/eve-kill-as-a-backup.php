<?php

/*
   As a protection against Squizz, the biggest threat to zkill, this
   script was created to ensure zkillboard is in sync with eve-kill.
   Karbo of eve-kill does the same and syncs against zkill,  having
   multiple sources like this is fantastic!
 */

require_once "../init.php";

$epoch = time();

do {
    $date = date("Y-m-d", $epoch);
    $epoch -= 86400;
    if ($date > "2025-12-19") continue;
    if ($date < "2009-02-01") exit();
    $i = pcntl_fork();
    if ($i == 0) {
        echo "$date\n";
        $raw = file_get_contents("https://api.eve-kill.com/history/{$date}");
        if ($raw != "") {
            $json = json_decode($raw, true);
            foreach ($json['data'] as $killID=>$hash) {
                $killID = (int) $killID;
                $row = $mdb->findDoc("crestmails", ['killID' => $killID, 'hash' => $hash]);
                if ($row === null) {
                    echo "$killID $hash\n";
                    $mdb->insert("crestmails", ['killID' => $killID, 'hash' => $hash, 'processed' => false]);
                }
            }

        }
        exit();
    }
    sleep(1);
} while ($epoch > 1133745709);
