<?php

require_once '../init.php';

$raw = file_get_contents("$baseDir/setup/items.json");
$json = json_decode($raw, true);

foreach ($json as $row) {
    $doc = $mdb->find('information', ['type' => 'typeID', 'id' => (int) $row['typeID']]);
    if ($doc === null || (is_array($doc) && sizeof($doc) == 0)) {
        Util::out('Adding '.$row['name']);
        $row['id'] = $row['typeID'];
        unset($row['typeID']);
        $row['type'] = 'typeID';
        $mdb->save('information', $row);
    }
    $row = null;
}

$redis->set('tq:itemsPopulated', true);
