<?php

require_once "../init.php";

global $mdb;

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

$sdeTypes = $mdb->getCollection("sde_types");
$cursor = $mdb->getCollection("information")->find(['categoryID' => 6]);
foreach ($cursor as $row) {
    $typeID = (int) $row['id'];
    $typeKeys = [$typeID, (string) $typeID];
    $type = $sdeTypes->findOne(
        ['$or' => [
            ['key' => ['$in' => $typeKeys]],
            ['_key' => ['$in' => $typeKeys]],
        ]],
        ['projection' => ['metaGroupID' => 1]]
    );
    $metaGroupID = (int) @$type['metaGroupID'];
    if ($metaGroupID > 0) {
        if (!isset($metaIdToKey[$metaGroupID])) continue;
        $mapped = $metaIdToKey[$metaGroupID];
        $file = $pipMap[$mapped];
        echo $row['name'] . " $metaGroupID " . $typeID . " $file\n";
        $n = $mdb->set("information", ['type' => 'typeID', 'id' => $typeID], ['pip' => $file]);
		if ($n['n'] > 0) {
			echo "  Updated\n";
		}
    }
}
