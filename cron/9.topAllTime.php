<?php

require_once '../init.php';

$date = date('Ymd');
$redisKey = "tq:topAllTime:$date";
$queueTopAlltime = new RedisQueue("queueTopAlltime");
if ($redis->get($redisKey) != true)
{
	$queueTopAlltime->clear();
	$iter = $mdb->getCollection('statistics')->find([], ['months' => 0, 'groups' => 0, 'topAllTime' => 0]);
	while ($row = $iter->next()) {
		if ($row['type'] == 'characterID') continue;

		$allTimeSum = (int) @$row['allTimeSum'];
		$currentSum = (int) @$row['shipsDestroyed'];

		if ($currentSum == 0) continue;
		if ($currentSum == $allTimeSum) continue;
		if (($currentSum - $allTimeSum) < ($allTimeSum * 0.01)) continue;

		$queueTopAlltime->push($row['_id']);
	}
}

$redis->setex($redisKey, 86400, true);

if ($redis->llen('queueStats') > 100) exit();
while ($id = $queueTopAlltime->pop()) {
	$row = $mdb->findDoc('statistics', ['_id' => $id]);
	calcTop($row);
	if ($redis->llen('queueStats') > 100) exit();
}

function calcTop($row)
{
	global $mdb;

	$timer = new Timer();
	$currentSum = (int) @$row['shipsDestroyed'];

	$parameters = [$row['type'] => $row['id']];
	$parameters['limit'] = 10;
	$parameters['kills'] = true;

	$topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $parameters));
	$topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $parameters, true));
	$topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $parameters, true));
	$topLists[] = array('type' => 'faction', 'data' => Stats::getTop('factionID', $parameters, true));
	$topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $parameters, true));
	$topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $parameters, true));

	$mdb->set('statistics', $row, ['topAllTime' => $topLists, 'allTimeSum' => $currentSum]);
	if ($timer->stop() > 60000) exit();
}
