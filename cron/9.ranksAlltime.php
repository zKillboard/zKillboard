<?php

require_once "../init.php";

$today = date('Ymd', time() - (3600 * 4));
$todaysKey = "RC:alltimeRanksCalculated:$today";
if ($redis->get($todaysKey) == true) exit();

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$information = $mdb->getCollection("statistics");

Util::out("Alltime ranks - first iteration");
$types = [];
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];

	$types[$type] = true;
	$key = "tq:ranks:alltime:$type:$today";

	$multi = $redis->multi();
	zAdd($multi, "$key:shipsDestroyed", @$row['shipsDestroyed'], $id);
	zAdd($multi, "$key:shipsLost", @$row['shipsLost'], $id);
	zAdd($multi, "$key:pointsDestroyed", @$row['pointsDestroyed'], $id);
	zAdd($multi, "$key:pointsLost", @$row['pointsLost'], $id);
	zAdd($multi, "$key:iskDestroyed", @$row['iskDestroyed'], $id);
	zAdd($multi, "$key:iskLost", @$row['iskLost'], $id);
	$multi->exec();
}

Util::out("Alltime ranks - second iteration");
foreach ($types as $type=>$value)
{
	$key = "tq:ranks:alltime:$type:$today";
	$indexKey = "$key:shipsDestroyed";
	$max = $redis->zCard($indexKey);
	$redis->del("tq:ranks:alltime:$type:$today");

	$it = NULL;
	while($arr_matches = $redis->zScan($indexKey, $it)) {
		foreach($arr_matches as $id => $score) {
			$shipsDestroyed = $redis->zScore("$key:shipsDestroyed", $id); 
			$shipsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:shipsDestroyed", $id));
			$shipsLost = $redis->zScore("$key:shipsLost", $id); 
			$shipsLostRank = rankCheck($max, $redis->zRevRank("$key:shipsLost", $id));
			$shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

			$iskDestroyed = $redis->zScore("$key:iskDestroyed", $id); 
			if ($iskDestroyed == 0) continue;
			$iskDestroyedRank = rankCheck($max, $redis->zRevRank("$key:iskDestroyed", $id));
			$iskLost = $redis->zScore("$key:iskLost", $id); 
			$iskLostRank = rankCheck($max, $redis->zRevRank("$key:iskLost", $id));
			$iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

			$pointsDestroyed = $redis->zScore("$key:pointsDestroyed", $id); 
			$pointsDestroyedRank = rankCheck($max, $redis->zRevRank("$key:pointsDestroyed", $id));
			$pointsLost = $redis->zScore("$key:pointsLost", $id); 
			$pointsLostRank = rankCheck($max, $redis->zRevRank("$key:pointsLost", $id));
			$pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

			$avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
			$adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
			$score = ceil($avg / $adjuster);

			$redis->zAdd("tq:ranks:alltime:$type:$today", $score, $id);
		}
	}
}

foreach ($types as $type=>$value) {
	$multi = $redis->multi();
	$multi->zUnion("tq:ranks:alltime:$type", ["tq:ranks:alltime:$type:$today"]);
	$multi->expire("tq:ranks:alltime:$type", 100000);
	$multi->expire("tq:ranks:alltime:$type:$today", (7 * 86400));
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:shipsDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:shipsLost");
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:iskDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:iskLost");
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:pointsDestroyed");
	moveAndExpire($multi, $today, "tq:ranks:alltime:$type:$today:pointsLost");
	$multi->exec();
}

$redis->setex($todaysKey, 87000, true);
Util::out("Alltime rankings complete");

function zAdd(&$multi, $key, $value, $id) {
	$value = max(1, (int) $value);
	$multi->zAdd($key, $value, $id);
	$multi->expire($key, 100000);
}

function moveAndExpire(&$multi, $today, $key) {
	$newKey = str_replace(":$today", "", $key);
	$multi->rename($key, $newKey);
	$multi->expire($newKey, 100000);
}

function rankCheck($max, $rank) {
	return $rank === false ? $max : ($rank + 1);
}
