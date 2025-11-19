<?php

require_once '../init.php';

$periods = [
	'weekly' => 'oneWeek',
	'recent' => 'ninetyDays',
	'alltime' => 'killmails',
];

$periodCache = [
	'weekly' => 4444,
	'recent' => 11111,
	'alltime' => 88888,
];

$types = [
	'factionID' => 'involved.factionID',
	'allianceID' => 'involved.allianceID',
	'corporationID' => 'involved.corporationID',
	'characterID' => 'involved.characterID',
	'shipTypeID' => 'involved.shipTypeID',
	'groupID' => 'involved.groupID',

	'locationID' => 'zkb.locationID',
	'solarSystemID' => 'system.solarSystemID',
	'constellationID' => 'system.constellationID',
	'regionID' => 'system.regionID',
];

$minute = date('Hi');
foreach ($periods as $period => $collection) {
	foreach ($types as $type => $field) {
		if (date('Hi') !== $minute) break;

		$redisKey = "zkb:{$period}RanksCalculated:{$type}";
		if ($redis->get($redisKey) != 'true') {
			calculateRanks($period, $collection, $type, $field, false);
			calculateRanks($period, $collection, $type, $field, true);

			$redis->setex($redisKey, $periodCache[$period], 'true');

			exit(); // process only one type per run
		}
	}
}

function calculateRanks($period, $collection, $type, $field, $solo)
{
	global $mdb;

	status($period, $type, $solo, "starting");

	$disqualified = $mdb->getCollection('information')->distinct('id', ['type' => $type, 'disqualified' => true]);
	$filter = [];
	if ($type != 'label') $filter['labels'] = 'pvp';
	if ($solo) $filter['solo'] = true;

	$cursor = null;

	if ($collection == 'killmails') {
		// Iterate the type from statistics to get unique ids
		$cursor = $mdb->getCollection('statistics')->find(['type' => $type, ($solo ? 'shipsDestroyedSolo' : 'shipsDestroyed') => ['$gt' => 10]], ['projection' => ['id' => 1]]);
	} else {
		// get the distincts
		$cursor = $mdb->getCollection($collection)->distinct($field, $filter);
		// map them into an iterable cursor-like structure
		$cursor = array_map(function ($id) {
			return ['id' => $id];
		}, $cursor);
	}

	$entityStats = [];

	// Iterate the cursor
	foreach ($cursor as $row) {
		$id = $row['id'];

		// Is this $id disqualified?
		if (in_array($id, $disqualified) || ($type == 'corporationID' && $id <= 1999999)) continue;

		$kills = getStats($type, $id, false, $collection, $solo);
		$losses = getStats($type, $id, true, $collection, $solo);
		if ($kills['killIDCount'] + $losses['killIDCount'] == 0) continue;

		$entityStats[$id] = [
			'shipsDestroyed' => $kills['killIDCount'],
			'shipsLost' => $losses['killIDCount'],
			'iskDestroyed' => $kills['zkb_totalValueSum'],
			'iskLost' => $losses['zkb_totalValueSum'],
			'pointsDestroyed' => $kills['zkb_pointsSum'],
			'pointsLost' => $losses['zkb_pointsSum'],
		];
	}

	//status($period, $type, $solo, 'applying ranks');

	$ranks = [];
	$first = reset($entityStats);
	if ($first === false || empty($entityStats)) {
		status($period, $type, $solo, 'no entities to rank, skipping');
		return;
	}
	
	foreach ($first as $field => $value) {
		uasort($entityStats, customSort($field));

		$rank = 1;
		foreach ($entityStats as $id => $entry) {
			$ranks[$id][$field] = $rank;
			$rank++;
		}
	}

	//status($period, $type, $solo, 'calculating efficiencies and overall rank');
	// Now calculate efficiencies and overall rank
	foreach ($entityStats as $id => $stats) {
		// Calculate efficiencies
		$entityStats[$id]['shipsEfficiency'] = ($stats['shipsDestroyed'] + $stats['shipsLost']) > 0 ? $stats['shipsDestroyed'] / ($stats['shipsDestroyed'] + $stats['shipsLost']) : 0;
		$entityStats[$id]['iskEfficiency'] = ($stats['iskDestroyed'] + $stats['iskLost']) > 0 ? $stats['iskDestroyed'] / ($stats['iskDestroyed'] + $stats['iskLost']) : 0;
		$entityStats[$id]['pointsEfficiency'] = ($stats['pointsDestroyed'] + $stats['pointsLost']) > 0 ? $stats['pointsDestroyed'] / ($stats['pointsDestroyed'] + $stats['pointsLost']) : 0;
		
		// Calculate overall score
		$avg = ceil(($ranks[$id]['shipsDestroyed'] + $ranks[$id]['iskDestroyed'] + $ranks[$id]['pointsDestroyed']) / 3);
		$adjuster = (1 + $entityStats[$id]['shipsEfficiency'] + $entityStats[$id]['iskEfficiency'] + $entityStats[$id]['pointsEfficiency']) / 4;
		$entityStats[$id]['score'] = ceil($avg / $adjuster);
	}

	uasort($entityStats, customSort("score"));
	$rank = 1;
	foreach ($entityStats as $id => $stats) {
		$ranks[$id]['overall'] = $rank;
		$rank++;
	}
		
	// Now bulk update the statistics collection with the new ranks
	//status($period, $type, $solo, 'bulk updating ranks');

	$suffix = $solo ? '_solo' : '';
	$fieldPrefix = "ranks.{$period}{$suffix}";

	$bulk = new MongoDB\Driver\BulkWrite(['ordered' => false]);

	// Convert any ranks arrays to objects
	$bulk->update(
		['type' => $type, 'ranks' => ['$type' => 'array']],
		['$set' => ['ranks' => new stdClass()]],
		['multi' => true]
	);

	// Clear existing ranks for this type/period/solo combination
	$bulk->update(
		['type' => $type],
		['$unset' => [$fieldPrefix => '']],
		['multi' => true, 'upsert' => false]
	);

	// Then set the new ranks
	foreach ($ranks as $id => $rankData) {
		$filter = ['type' => $type, 'id' => $id];
		$update = ['$set' => []];

		foreach ($rankData as $statKey => $statValue) {
			$update['$set']["{$fieldPrefix}.{$statKey}"] = $statValue;
		}

		// Set last updated timestamp for this period
		$update['$set']["{$fieldPrefix}.updated"] = time();

		$bulk->update($filter, $update, ['multi' => false, 'upsert' => false]);
	}

	try {
		$client = $mdb->getClient();
		$manager = $client->getManager();
		$manager->executeBulkWrite('zkillboard.statistics', $bulk);
		status($period, $type, $solo, 'completed');
	} catch (Exception $e) {
		Util::out('Error batch updating ranks: ' . $e->getMessage());
	}
}

// Custom sort function that takes the field to compare and then orders on that
function customSort($field)
{
	return function ($a, $b) use ($field) {
		if ($a[$field] == $b[$field]) return 0;
		return ($a[$field] > $b[$field]) ? -1 : 1;
	};
}

/**
 * Get stats for an entity from recent/weekly collections
 */
function getStats($type, $id, $isVictim, $collection, $solo)
{
	global $mdb;

	if ($collection == 'killmails') {
		$stats = $mdb->findDoc('statistics', [
			'type' => $type,
			'id' => $id
		]);
		if ($stats) {
			$statFields = [
				'shipsDestroyed' => $solo ? 'shipsDestroyedSolo' : 'shipsDestroyed',
				'shipsLost' => $solo ? 'shipsLostSolo' : 'shipsLost',
				'iskDestroyed' => $solo ? 'iskDestroyedSolo' : 'iskDestroyed',
				'iskLost' => $solo ? 'iskLostSolo' : 'iskLost',
				'pointsDestroyed' => $solo ? 'pointsDestroyedSolo' : 'pointsDestroyed',
				'pointsLost' => $solo ? 'pointsLostSolo' : 'pointsLost',
			];
		}
		return [
			'killIDCount' => $isVictim ? (int) @$stats[$statFields['shipsLost']] : (int) @$stats[$statFields['shipsDestroyed']],
			'zkb_totalValueSum' => $isVictim ? (int) @$stats[$statFields['iskLost']] : (int) @$stats[$statFields['iskDestroyed']],
			'zkb_pointsSum' => $isVictim ? (int) @$stats[$statFields['pointsLost']] : (int) @$stats[$statFields['pointsDestroyed']],
		];
	}

	$query = [$type => $id, 'isVictim' => $isVictim];

	if ($solo) {
		$query['solo'] = true;
	}

	if ($type != 'label') {
		unset($query['labels']);
		$query['npc'] = false;
	}

	$query = MongoFilter::buildQuery($query);

	$result = $mdb->group($collection, [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
	return sizeof($result) ? $result[0] : ['killIDCount' => 0, 'zkb_pointsSum' => 0, 'zkb_totalValueSum' => 0];
}

function status($period, $type, $solo, $msg)
{
	$soloLabel = $solo ? 'solo ' : '';
	Util::out("Ranks: {$period} $type {$soloLabel}ranks: $msg");
}
