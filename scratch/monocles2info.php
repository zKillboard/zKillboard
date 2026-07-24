<?php

require_once __DIR__ . "/../init.php";

$dryRun = in_array('--dry-run', $argv, true);

$unsetMonocleQuery = ['type' => 'characterID', 'monocle' => ['$exists' => true]];
$unsetSuperMonocleQuery = ['type' => 'characterID', 'supermonocle' => ['$exists' => true]];

if ($dryRun) {
    Util::out("Would clear monocle from " . $mdb->count("information", $unsetMonocleQuery) . " information rows");
    Util::out("Would clear supermonocle from " . $mdb->count("information", $unsetSuperMonocleQuery) . " information rows");
} else {
    $mdb->removeField("information", $unsetMonocleQuery, 'monocle', true);
    $mdb->removeField("information", $unsetSuperMonocleQuery, 'supermonocle', true);
}

$rows = $mdb->find("users", ['$or' => [['monocle' => true], ['supermonocle' => true]]], [], null, ['_id' => 0, 'characterID' => 1, 'monocle' => 1, 'supermonocle' => 1]);
$monocles = 0;
$superMonocles = 0;
foreach ($rows as $row) {
    $id = (int) @$row['characterID'];
    if ($id <= 0) continue;

	$userInfo = $mdb->findDoc("users", ['characterID' => $id]);
    $shinyPortraits = @$userInfo['shinyPortraits'];
    if (is_string($shinyPortraits)) {
        $decodedShinyPortraits = json_decode($shinyPortraits, true);
        if ($decodedShinyPortraits !== null) $shinyPortraits = $decodedShinyPortraits;
    }
    if ($shinyPortraits === false || $shinyPortraits == 'false') continue;
	
	$values = ['monocle' => true];
    if (!empty($row['supermonocle'])) {
        $values['supermonocle'] = true;
        $superMonocles++;
    }
    $monocles++;

    if (!$dryRun) {
        $mdb->set("information", ['type' => 'characterID', 'id' => $id], $values);
        $redis->del(Info::getRedisKey('characterID', $id));
        $redis->del("zkb:overview:character:$id");
        $redis->del("zkb:overview:characterID:$id");
        $redis->sadd("queueCacheTags", "overview:$id");
    }
}

Util::out(($dryRun ? "Would update" : "Updated") . " $monocles monocles and $superMonocles super monocles in information");
