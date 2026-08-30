<?php

require_once "../init.php";

$key = "zkb:trophy-leaders:" . date('Ymd');
if ($kvc->get($key) == "true") exit();

$latest = $mdb->findDoc(
	'trophies',
	['trophies.calcTrophies_updated' => ['$gt' => 0]],
	['trophies.calcTrophies_updated' => -1],
	['_id' => 0, 'trophies.calcTrophies_updated' => 1]
);
$sourceUpdated = (int) ($latest['trophies']['calcTrophies_updated'] ?? 0);

$rows = $mdb->getCollection('trophies')->aggregate([
	['$match' => ['trophies.id' => ['$gt' => 0], 'trophies.trophies' => ['$exists' => true]]],
	['$lookup' => [
		'from' => 'information',
		'let' => ['characterID' => '$trophies.id'],
		'pipeline' => [
			['$match' => ['$expr' => ['$and' => [
				['$eq' => ['$type', 'characterID']],
				['$eq' => ['$id', '$$characterID']],
			]]]],
			['$project' => ['_id' => 0, 'birthday' => 1]],
			['$limit' => 1],
		],
		'as' => 'character',
	]],
	['$project' => [
		'_id' => 0,
		'characterID' => '$trophies.id',
		'trophies' => '$trophies.trophies',
		'levelCount' => '$trophies.levelCount',
		'birthday' => ['$arrayElemAt' => ['$character.birthday', 0]],
	]],
], ['allowDiskUse' => true]);

$trophyLeaders = [];
foreach ($rows as $row) {
	$characterID = (int) ($row['characterID'] ?? 0);
	$levelCount = (int) ($row['levelCount'] ?? 0);
	$birthday = $row['birthday'] ?? null;
	if ($birthday instanceof MongoDB\BSON\UTCDateTime) {
		$birthday = $birthday->toDateTime()->getTimestamp();
	} else {
		$birthday = strtotime((string) $birthday);
		if ($birthday === false) $birthday = null;
	}

	foreach ((array) ($row['trophies'] ?? []) as $category => $trophies) {
		foreach ((array) $trophies as $name => $trophy) {
			$trophy = (array) $trophy;
			$value = (int) ($trophy['value'] ?? 0);
			$current = $trophyLeaders[$category][$name] ?? null;
			$currentLevelCount = $current['levelCount'] ?? 0;
			$currentBirthday = $current['birthday'] ?? null;
			$winsTie = $current !== null && $value == $current['value'] && (
				$levelCount > $currentLevelCount
				|| ($levelCount == $currentLevelCount && (
					($birthday !== null && ($currentBirthday === null || $birthday < $currentBirthday))
					|| ($birthday === $currentBirthday && $characterID < $current['characterID'])
				))
			);
			if ($current === null || $value > $current['value'] || $winsTie) {
				$trophyLeaders[$category][$name] = [
					'characterID' => $characterID,
					'value' => $value,
					'level' => (int) ($trophy['level'] ?? 0),
					'levelCount' => $levelCount,
					'birthday' => $birthday,
				];
				if (isset($trophy['total'])) $trophyLeaders[$category][$name]['total'] = (int) $trophy['total'];
			}
		}
	}
}

foreach ($trophyLeaders as &$trophies) {
	foreach ($trophies as &$leader) unset($leader['levelCount'], $leader['birthday']);
}
unset($trophies, $leader);

$mdb->insertUpdate('trophies', ['id' => 0], [
	'trophyLeaders' => $trophyLeaders,
	'sourceUpdated' => $sourceUpdated,
	'updated' => Mdb::now(),
]);
$redis->sadd("queueCacheTags", "trophies");
$kvc->setex($key, 86400, "true");
