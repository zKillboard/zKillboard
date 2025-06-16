<?php

require_once "../init.php";

$handle = fopen("/tmp/id_hash.csv", "r");

$count = 0;
if ($handle !== false) {
    $headers = fgetcsv($handle);
    while (($data = fgetcsv($handle)) !== false) {
        $row = array_combine($headers, $data);
        if ($mdb->count("crestmails", ['killID' => (int) $row['id']]) == 0) {
            echo $row['id'] . "\n";
            $id = (int) $row['id'];
            $hash = $row['killmail_hash'];
            $mdb->insert("crestmails", ['killID' => $id, 'hash' => $hash, 'processed' => false, 'source' => 'csv']);
            $count++;
        }
    }
    fclose($handle);
}
echo "added $count\n";
