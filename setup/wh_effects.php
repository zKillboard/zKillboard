<?php

require_once "../init.php";

$sig2class = [];
$csv = file_get_contents("$baseDir/setup/sig2class.csv");
$data = str_getcsv($csv, "\n");
print_r($data);
foreach($data as $row) {
    $info = str_getcsv($row, ",");
    $dest = $info[0];
    $sigs = split(",", $info[1]);
    foreach($sigs as $sig) $sig2class[$sig] = $dest;
}

$csv = file_get_contents("$baseDir/setup/wh_effects.csv");
$data = str_getcsv($csv, "\n");
foreach ($data as $row) {
    $info = str_getcsv($row, ",");
    $name = $info[0];
    $class = $info[4];
    $effects = $info[5];
    $statics = $info[6];

    $dbrow = $mdb->find("information", ['type' => 'solarSystemID', 'name' => $name]);
    if ($dbrow == null) continue;

    $updates = ['class' => $class];
    if ($effects != '') $updates['effects'] = $effects;
    //if ($statics != '') $updates['statics'] = $statics;

    if ($statics) {
        $send = "";
        $statics = split(",", $statics);
        foreach ($statics as $sig) {
            $sig = trim($sig);
            $dest = $sig2class[$sig];
            if ($send != "") $send .= ", ";
            $send .= "$dest ($sig)";
        }
        echo "$name, $send\n";
        if ($send) $updates['statics'] = $send;
    }
    
    $mdb->set("information", ['type' => 'solarSystemID', 'name' => $name], $updates);
}
