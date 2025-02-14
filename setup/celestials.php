<?php

require_once '../init.php';

$raw = file_get_contents("$baseDir/setup/celestials.json");
$json = json_decode($raw, true);

foreach ($json as $row) {
    echo $row['CelestialID'] . "\n";
    $mdb->insert("celestials", $row);
}
