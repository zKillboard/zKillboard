<?php

require_once "../init.php";

global $sdeLocation;


$typesFile = $sdeLocation . "/types.jsonl";

// ensure the file exists
if (!file_exists($typesFile)) {
	die("typesFile does not exist: $typesFile\n");
}

$metaIdToKey = [
    1  => 'tech1',
    2  => 'tech2',
    14 => 'tech3',
    4  => 'faction',
    3  => 'storyline',
    6  => 'deadspace',
    5  => 'officer',
];

$pipMap = [
    'tech1'     => 'pip_tech1.png',
    'tech2'     => 'pip_tech2.png',
    'tech3'     => 'pip_tech3.png',
    'faction'   => 'pip_faction.png',
    'storyline' => 'pip_storyline.png',
    'deadspace' => 'pip_deadspace.png',
    'officer'   => 'pip_officer.png',
];

$raw = file_get_contents($typesFile);
// read each line individually
$json = [];
$lines = explode("\n", $raw);
foreach ($lines as $line) {
    if (trim($line) === '') continue;
    $json[] = json_decode($line, true);
}
$metaTypes = [];
foreach($json as $row) {
    $metaTypes[$row['_key']] = $row;
}

$cursor = $mdb->getCollection("information")->find(['categoryID' => 6]);
foreach ($cursor as $row) {
    $metaGroupID = (int) @$metaTypes[$row['id']]['metaGroupID'];
    if ($metaGroupID > 0) {
        $mapped = $metaIdToKey[$metaGroupID];
        $file = $pipMap[$mapped];
        echo $row['name'] . " $metaGroupID " . $row['id'] . " $file\n";
        $n = $mdb->set("information", ['type' => 'typeID', 'id' => $row['id']], ['pip' => $file]);
		if ($n['n'] > 0) {
			echo "  Updated\n";
		}
    }
}
