<?php

require_once '../init.php';

$periods = [
	'weekly' => 'oneWeek',
	'recent' => 'ninetyDays',
	'alltime' => 'killmails',
];

$periodOffsets = [
	'weekly' => ['ttl' => 3600, 'offset' => -900], // 1hr, -15 minutes offset (HH:45)
	'recent' => ['ttl' => 28800, 'offset' => 1800], // 8hr, 30 minutes offset (HH:30)
	'alltime' => ['ttl' => 86400, 'offset' => 25200], // 24hr, 7 hours offset (07:00)
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
	$ttl = $periodOffsets[$period]['ttl'];
	$offset = $periodOffsets[$period]['offset'];
	$time = time() - $offset;
	$epoch = $time - ($time % $ttl);
	
	foreach ($types as $type => $field) {
		if (date('Hi') !== $minute)
			break;

		$redisKey = "zkb:ranks:{$period}:{$type}:$epoch:a";
		if ($redis->get($redisKey) != 'true') {
			$success = calculateRanks($period, $collection, $type, $field, false);
			if ($success) {
				$success = calculateRanks($period, $collection, $type, $field, true);
			}

			if ($success) {
				$redis->setex($redisKey, $ttl, 'true');
			} else {
				Util::out("Failed to calculate ranks for {$period} {$type}");
				exit();
			}
		}
	}
}

function calculateRanks($period, $collection, $type, $field, $solo)
{
	global $mdb;

	status($period, $type, $solo, 'starting');

	$disqualified = array_flip($mdb->getCollection('information')->distinct('id', ['type' => $type, 'disqualified' => true]));
	$filter = [];
	if ($type != 'label')
		$filter['labels'] = 'pvp';
	if ($solo)
		$filter['solo'] = true;

	$cursor = null;

	// Used for alltime killmails to map stats fields
	$statFields = [
		'shipsDestroyed' => $solo ? 'shipsDestroyedSolo' : 'shipsDestroyed',
		'shipsLost' => $solo ? 'shipsLostSolo' : 'shipsLost',
		'iskDestroyed' => $solo ? 'iskDestroyedSolo' : 'iskDestroyed',
		'iskLost' => $solo ? 'iskLostSolo' : 'iskLost',
		'pointsDestroyed' => $solo ? 'pointsDestroyedSolo' : 'pointsDestroyed',
		'pointsLost' => $solo ? 'pointsLostSolo' : 'pointsLost',
	];

	if ($collection == 'killmails') {
		// Iterate the type from statistics with all needed fields
		$projection = ['id' => 1];
		foreach ($statFields as $dbField) {
			$projection[$dbField] = 1;
		}
		$cursor = $mdb->getCollection('statistics')->find(
			['type' => $type, $statFields['shipsDestroyed'] => ['$gte' => 100]],
			['projection' => $projection]
		);
	} else {
		// get the distincts
		$cursor = $mdb->getCollection($collection)->distinct($field, $filter);
		// map them into an iterable cursor-like structure
		$cursor = array_map(function ($id) {
			return ['id' => $id];
		}, $cursor);
	}

	$entityStats = [];

	$minimumShipsDestroyed = ($period == "recent") ? 10 : 1;

	// Iterate the cursor
	foreach ($cursor as $row) {
		$id = $row['id'];

		// Is this $id disqualified?
		if (isset($disqualified[$id]))
			continue;
		if ($type == 'corporationID' && $id <= 1999999)
			continue;

		if ($collection == 'killmails') {
			// Use data already fetched from statistics cursor
			$entityStats[$id] = [
				'shipsDestroyed' => (int) @$row[$statFields['shipsDestroyed']],
				'shipsLost' => (int) @$row[$statFields['shipsLost']],
				'iskDestroyed' => (int) @$row[$statFields['iskDestroyed']],
				'iskLost' => (int) @$row[$statFields['iskLost']],
				'pointsDestroyed' => (int) @$row[$statFields['pointsDestroyed']],
				'pointsLost' => (int) @$row[$statFields['pointsLost']],
			];
		} else {
			// if they don't already have the minimum ships destroyed alltime, skip them
			if (@$row[$statFields['shipsDestroyed']] < $minimumShipsDestroyed)
				continue;

			$kills = getStats($type, $id, false, $collection, $solo);
			if ($kills['killIDCount'] < $minimumShipsDestroyed) continue;

			$losses = getStats($type, $id, true, $collection, $solo);

			$entityStats[$id] = [
				'shipsDestroyed' => $kills['killIDCount'],
				'shipsLost' => $losses['killIDCount'],
				'iskDestroyed' => $kills['zkb_totalValueSum'],
				'iskLost' => $losses['zkb_totalValueSum'],
				'pointsDestroyed' => $kills['zkb_pointsSum'],
				'pointsLost' => $losses['zkb_pointsSum'],
			];
		}
	}

	// status($period, $type, $solo, 'applying ranks');

	$ranks = [];
	$first = reset($entityStats);
	if ($first === false || empty($entityStats)) {
		status($period, $type, $solo, 'no entities to rank, skipping');
		return true;
	}

	foreach ($first as $field => $value) {
		uasort($entityStats, customSort($field));

		$rank = 0;
		$lastValue = PHP_INT_MAX;
		foreach ($entityStats as $id => $entry) {
			if ($entry[$field] < $lastValue) {
				$rank++;
				$lastValue = $entry[$field];
			}
			$ranks[$id][$field] = $rank;
		}
	}

	// status($period, $type, $solo, 'calculating efficiencies and overall rank');
	// Now calculate efficiencies and overall rank
	foreach ($entityStats as $id => $stats) {
		// Calculate efficiencies
		$entityStats[$id]['shipsEfficiency'] = ($stats['shipsDestroyed'] + $stats['shipsLost']) > 0 ? $stats['shipsDestroyed'] / ($stats['shipsDestroyed'] + $stats['shipsLost']) : 0;
		$entityStats[$id]['iskEfficiency'] = ($stats['iskDestroyed'] + $stats['iskLost']) > 0 ? $stats['iskDestroyed'] / ($stats['iskDestroyed'] + $stats['iskLost']) : 0;
		$entityStats[$id]['pointsEfficiency'] = ($stats['pointsDestroyed'] + $stats['pointsLost']) > 0 ? $stats['pointsDestroyed'] / ($stats['pointsDestroyed'] + $stats['pointsLost']) : 0;

		// Calculate overall score (lower is better)
		$avg = ($ranks[$id]['shipsDestroyed'] + $ranks[$id]['iskDestroyed'] + $ranks[$id]['pointsDestroyed']) / 3;
		$adjuster = (1 + $entityStats[$id]['shipsEfficiency'] + $entityStats[$id]['iskEfficiency'] + $entityStats[$id]['pointsEfficiency']) / 4;
		$entityStats[$id]['score'] = round($avg / $adjuster, 5);
	}

	// Sort by score ascending (lower score = better rank)
	uasort($entityStats, function ($a, $b) {
		if ($a['score'] == $b['score'])
			return 0;
		return ($a['score'] < $b['score']) ? -1 : 1;
	});

	$rank = 0;
	$lastValue = -1;
	foreach ($entityStats as $id => $stats) {
		if ($stats['score'] > $lastValue) {
			$rank++;
			$lastValue = $stats['score'];
		}
		$ranks[$id]['overall'] = $rank;
	}

	// Now bulk update the statistics collection with the new ranks
	// status($period, $type, $solo, 'bulk updating ranks');

	$suffix = $solo ? '_solo' : '';
	$fieldPrefix = "ranks.{$period}{$suffix}";
	$statsPrefix = "stats.{$period}{$suffix}";
	$time = time();

	// Convert any ranks arrays to objects FIRST, before bulk operations
	try {
		$mdb->getCollection('statistics')->updateMany(
			['type' => $type, 'ranks' => ['$type' => 'array']],
			['$unset' => ['ranks' => 1]]
		);
	} catch (Exception $e) {
		// Ignore if already converted
	}

	$bulk = new MongoDB\Driver\BulkWrite(['ordered' => false]);

	// Store the stats the ranks are based on
	foreach ($entityStats as $id => $stats) {
		$filter = ['type' => $type, 'id' => $id];
		$update = ['$set' => []];

		foreach ($stats as $statKey => $statValue) {
			$update['$set']["stats.{$period}{$suffix}.{$statKey}"] = $statValue;
		}

		$bulk->update($filter, $update, ['multi' => false, 'upsert' => false]);
	}

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
		$update['$set']["{$fieldPrefix}.updated"] = $time;

		$bulk->update($filter, $update, ['multi' => false, 'upsert' => false]);
	}

	// add the overall rank to ranks_history based on today's date
	$dateStr = date('Y-m-d');
	$historyField = $solo ? "ranks_history.{$period}_solo" : "ranks_history.{$period}";

	foreach ($ranks as $id => $rankData) {
		$filter = ['type' => $type, 'id' => $id];

		// Remove existing entry for today's date first to prevent duplicates
		$bulk->update(
			$filter,
			['$pull' => [$historyField => ['date' => $dateStr]]],
			['multi' => false, 'upsert' => false]
		);

		// Then add today's rank
		$update = ['$push' => []];
		$update['$push'][$historyField] = ['date' => $dateStr, 'rank' => $rankData['overall']];
		$bulk->update($filter, $update, ['multi' => false, 'upsert' => false]);
	}

	// clear any ranks history that are more than 7 days old (keep only last 7 days)
	$cutoffDate = date('Y-m-d', strtotime('-7 days'));
	$historyField = $solo ? "ranks_history.{$period}_solo" : "ranks_history.{$period}";

	$bulk->update(
		['type' => $type, $historyField => ['$exists' => true]],
		['$pull' => [$historyField => ['date' => ['$lt' => $cutoffDate]]]],
		['multi' => true, 'upsert' => false]
	);

	try {
		$client = $mdb->getClient();
		$manager = $client->getManager();
		$manager->executeBulkWrite('zkillboard.statistics', $bulk);
		status($period, $type, $solo, 'completed');
		return true;
	} catch (Exception $e) {
		if ($e->getCode() == 36) return true; // some updates failed, but not all
		Util::out('Error batch updating ranks: ' . $e->getMessage());
		return false;
	}
}

// Custom sort function that takes the field to compare and then orders on that
function customSort($field)
{
	return function ($a, $b) use ($field) {
		if ($a[$field] == $b[$field])
			return 0;
		return ($a[$field] > $b[$field]) ? -1 : 1;
	};
}

/**
 * Get stats for an entity from recent/weekly collections
 */
function getStats($type, $id, $isVictim, $collection, $solo)
{
	global $mdb;

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
