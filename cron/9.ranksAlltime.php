<?php

require_once "../init.php";

$today = date('Ymd');
$todaysKey = "RC:alltimeRanksCalculated:$today";
if ($redis->get($todaysKey) == true) exit();

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];
$cleanup = [];
$expires = [];

$information = $mdb->getCollection("statistics");

Util::out("All time ranks - first iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];
	$key = "tq:ranks:$type";
	foreach ($statClasses as $statClass) {
		foreach ($statTypes as $statType) {
			$value = (int) @$row["${statClass}${statType}"];
			$redisKey = "${key}:${statClass}${statType}";
			$redis->zAdd($redisKey, $value, $id);
			$cleanup[$redisKey] = true;
		}
	}
}

Util::out("All time ranks - second iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];
	$key = "tq:ranks:$type";
	$update = [];
	foreach ($statClasses as $statClass) {
		foreach ($statTypes as $statType) {
			$rank = 1 + $redis->zRevRank("${key}:${statClass}${statType}", $id);
			$update["${statClass}${statType}Rank"] = $rank;
		}
	}

	$shipsDestroyed = getValue($row, 'shipsDestroyed');
	$shipsDestroyedRank = getValue($update, 'shipsDestroyedRank');
	$shipsLost = getValue($row, 'shipsLost');
	$shipsLostRank = getValue($update, 'shipsLostRank');
	$shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

	$iskDestroyed = getValue($row, 'iskDestroyed');
	$iskDestroyedRank = getValue($update, 'iskDestroyedRank');
	$iskLost = getValue($row, 'iskLost');
	$iskLostRank = getValue($update, 'iskLostRank');
	$iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

	$pointsDestroyed = getValue($row, 'pointsDestroyed');
	$pointsDestroyedRank = getValue($row, 'pointsDestroyedRank');
	$pointsLost = getValue($row, 'pointsLost');
	$pointsLostRank = getValue($row, 'pointsLostRank');
	$pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

	$avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
	$adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
	$score = ceil($avg / $adjuster);
	$redis->zAdd("tq:ranks:$type:score", $score, $id);
	$mdb->set("statistics", $row, $update);
}

$count = 0;
Util::out("All time ranks - third iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];
	$cleanup["tq:ranks:$type:score"] = 1;
	$rank = 1 + $redis->zRank("tq:ranks:$type:score", $id);
	$mdb->set("statistics", $row, ['overallRank' => $rank]);
	$redis->hSet("tq:ranks:$type:alltime:$today", $id, $rank);
	$expires[$type] = true;
}

foreach ($expires as $type=>$value) $redis->expire("tq:ranks:$type:alltime:$today", (86400 * 15));
foreach ($cleanup as $key=>$value) $redis->del($key);


$redis->setex($todaysKey, 87000, true);

function getValue(&$array, $index) {
	$value = (int) @$array[$index];
	return $value > 0 ? $value : 1000000000000;
}
