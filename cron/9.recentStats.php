<?php

require_once "../init.php";

$today = date('Ymd', time() - (3600 * 4));
$todaysKey = "RC:recentRanksCalculated:$today";
if ($redis->get($todaysKey) == true) exit();

$keys = $redis->keys("*recent*");
foreach ($keys as $key) $redis->del($key);

$statClasses = ['ships', 'isk', 'points'];
$statTypes = ['Destroyed', 'Lost'];

$now = time();
$now = $now - ($now % 60);
$then = $now - (90 * 86400);
$ninetyDayKillID = null;
do {   
        $result = $mdb->getCollection('killmails')->find(['dttm' => new MongoDate($then)], ['killID' => 1])->sort(['killID' => 1])->limit(1);
        if ($row = $result->next()) {
                $ninetyDayKillID = (int) $row['killID'];
        } else {
                $then += 1;
        }
        if ($then > $now) exit();
} while ($ninetyDayKillID === null);
exit();

$information = $mdb->getCollection("statistics");

Util::out("recent time ranks - first iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];

	$killID = getLatestKillID($type, $id, $ninetyDayKillID);
	if ($killID < $ninetyDayKillID) continue;

	$key = "tq:ranks:recent:$type";

	$recentKills = getRecent($row['type'], $row['id'], true, $ninetyDayKillID); 
	$recentLosses = getRecent($row['type'], $row['id'], false, $ninetyDayKillID); 

	$update['recentShipsDestroyed'] = (int) $recentKills['killIDCount'];
	$redis->zAdd("$key:shipsDestroyed", (int) $recentKills['killIDCount'], $id);
	$update['recentPointsDestroyed'] = (int) $recentKills['zkb_pointsSum'];
	$redis->zAdd("$key:pointsDestroyed", (int) $recentKills['zkb_pointsSum'], $id);
	$update['recentIskDestroyed'] = (int) $recentKills['zkb_totalValueSum'];
	$redis->zAdd("$key:iskDestroyed", (int) $recentKills['zkb_totalValueSum'], $id);
	$update['recentShipsLost'] = (int) $recentLosses['killIDCount'];
	$redis->zAdd("$key:shipsLost", (int) $recentLosses['killIDCount'], $id);
	$update['recentPointsLost'] = (int) $recentLosses['zkb_pointsSum'];
	$redis->zAdd("$key:pointsLost", (int) $recentLosses['zkb_pointsSum'], $id);
	$update['recentIskLost'] = (int) $recentLosses['zkb_totalValueSum'];
	$redis->zAdd("$key:iskLost", (int) $recentLosses['zkb_totalValueSum'], $id);

	$mdb->set("statistics", ['type' => $type, 'id' => $id], $update);
}

Util::out("recent time ranks - second iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];
	$key = "tq:ranks:recent:$type";
	$update = [];
	foreach ($statClasses as $statClass) {
		foreach ($statTypes as $statType) {
			$rank = $redis->zRevRank("$key:${statClass}${statType}", $id);
			$st = ucwords("${statClass}${statType}Rank");
			$update["recent$st"] = $rank;
		}
	}

	$shipsDestroyed = getValue($row, 'shipsDestroyed');
	$iskDestroyed = getValue($row, 'iskDestroyed');
	$pointsDestroyed = getValue($row, 'pointsDestroyed');
	if ($shipsDestroyed > 0 && $iskDestroyed > 0 && $pointsDestroyed > 0) {
		$shipsDestroyedRank = getValue($update, 'shipsDestroyedRank');
		$shipsLost = getValue($row, 'shipsLost');
		$shipsLostRank = getValue($update, 'shipsLostRank');
		$shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

		$iskDestroyedRank = getValue($update, 'iskDestroyedRank');
		$iskLost = getValue($row, 'iskLost');
		$iskLostRank = getValue($update, 'iskLostRank');
		$iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

		$pointsDestroyedRank = getValue($row, 'pointsDestroyedRank');
		$pointsLost = getValue($row, 'pointsLost');
		$pointsLostRank = getValue($row, 'pointsLostRank');
		$pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

		$avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
		$adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
		$score = ceil($avg / $adjuster);
		$redis->zAdd("tq:ranks:recent:$type:score", $score, $id);
		$mdb->set("statistics", ['type' => $type, 'id' => $id], $update);
	}
	else
	{
		$mdb->getCollection('statistics')->update(['type' => $type, 'id' => $id], ['$unset' => ['recentShipsLost' => 1, 'recentPointsLost' => 1, 'recentIskLost' => 1, 'recentShipsDestroyed' => 1, 'recentPointsDestroyed' => 1, 'recentIskDestroyed' => 1, 'recentOverallRank' => 1, 'recentOverallScore' => 1]]);
	}
}

Util::out("recent time ranks - third iteration");
$iter = $information->find();
while ($row = $iter->next()) {
	$type = $row['type'];
	$id = $row['id'];
	$rank = $redis->zRank("tq:ranks:recent:$type:score", $id);
	if ($rank !== false) $mdb->set("statistics", $row, ['recentOverallRank' => (1 + $rank)]);
}

$keys = $redis->keys("*recent*");
foreach ($keys as $key) $redis->del($key);

$redis->setex($todaysKey, 87000, true);
Util::out("Recent rankings complete");

function getValue(&$array, $index) {
	$index = ucwords($index);
	$value = @$array["recent$index"];
	return $value;
}

function getRecent($type, $id, $isVictim, $ninetyDayKillID) {
	global $mdb;

	// build the query
	$query = [$type => $id, 'isVictim' => $isVictim];
	$query = MongoFilter::buildQuery($query);
	// set the proper sequence values
	$query = ['$and' => [['killID' => ['$gte' => $ninetyDayKillID]], $query]];

	$result = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
	return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}

function getLatestKillID($type, $id, $ninetyDayKillID) {
        global $mdb;

        // build the query
        $query = [$type => $id, 'isVictim' => false];
        $query = MongoFilter::buildQuery($query);
        // set the proper sequence values
        $query = ['$and' => [['killID' => ['$gte' => $ninetyDayKillID]], $query]];

	$killmail = $mdb->findDoc("killmails", $query);
	if ($killmail == null) return 0;
	return $killmail['killID'];
}
