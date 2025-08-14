<?php

require_once "../init.php";

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

$raw = file_get_contents("https://sde.zzeve.com/invMetaTypes.json");
$json = json_decode($raw, true);
$metaTypes = [];
foreach($json as $row) {
    $metaTypes[$row['typeID']] = $row;
}
$cursor = $mdb->getCollection("information")->find(['categoryID' => 6]);
while ($cursor->hasNext()) {
    $row = $cursor->next();
    $metaGroupID = (int) @$metaTypes[$row['id']]['metaGroupID'];
    if ($metaGroupID > 0) {
        $mapped = $metaIdToKey[$metaGroupID];
        $file = $pipMap[$mapped];
        echo $row['name'] . " $metaGroupID $file\n";
        $mdb->set("information", ['type' => 'typeID', 'id' => $row['id']], ['pip' => $file]);
    }
}
