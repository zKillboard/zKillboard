<?php

require_once "../init.php";

global $mdb, $redis, $kvc;

$options = getopt('', [
    'days::',
    'batch-size::',
    'min-kills::',
    'dry-run',
    'force',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php 9.inferredFits.php [options]\n";
    echo "  --days=90          Number of days to scan, max 180\n";
    echo "  --batch-size=1000   Killmail batch size\n";
    echo "  --min-kills=1       Minimum inferred kills before storing a fit\n";
    echo "  --dry-run           Build and print summary without writing fitkillers\n";
    echo "  --force             Ignore the daily completion guard\n";
    exit;
}

$days = min(90, optionInt($options, 'days', 90, 1));
$batchSize = optionInt($options, 'batch-size', 1000, 100);
$minKills = optionInt($options, 'min-kills', 1, 0);
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);
$cronKey = 'cron:inferredFits';

if (!$force && !$dryRun && $kvc->get($cronKey) == true) exit();
if (!$force && !$dryRun && Util::getLoad() >= 10) exit();

$runID = gmdate('YmdHis');
$startTime = time();
$sinceTime = $startTime - ($days * 86400);
$since = new MongoDB\BSON\UTCDateTime($sinceTime * 1000);

$stats = [];
$active = [];
$processed = 0;
$victimLosses = 0;
$fittedLosses = 0;
$missingEsi = 0;
$emptyFits = 0;
$matchedKills = 0;

$cursor = $mdb->getCollection('killmails')->find(
	[
		'npc' => false,
		'labels' => ['$all' => ['pvp', 'cat:6']],
		'dttm' => ['$gte' => $since],
	],
	[
		'projection' => [
			'_id' => 0,
			'killID' => 1,
			'dttm' => 1,
			'involved' => 1,
			'attackerCount' => 1,
			'solo' => 1,
			'zkb.totalValue' => 1,
		],
		'sort' => ['dttm' => -1, 'killID' => -1],
		'batchSize' => $batchSize,
		'noCursorTimeout' => true,
	]
);

$batch = [];
foreach ($cursor as $mail) {
	$batch[] = $mail;
	if (sizeof($batch) >= $batchSize) {
		processFitKillerBatch($batch, $stats, $active, $processed, $victimLosses, $fittedLosses, $missingEsi, $emptyFits, $matchedKills);
		$batch = [];
	}
}
processFitKillerBatch($batch, $stats, $active, $processed, $victimLosses, $fittedLosses, $missingEsi, $emptyFits, $matchedKills);

foreach (array_keys($active) as $key) {
	closeFitKillerLife($key, $stats, $active);
}

$rows = buildFitKillerRows($stats, $runID, $startTime, $days, $processed, $victimLosses, $fittedLosses, $matchedKills, $minKills);

if ($dryRun) {
	echo "fitkillers dry_run runID=$runID days=$days processed=$processed victim_losses=$victimLosses fitted_losses=$fittedLosses matched_kills=$matchedKills rows=" . sizeof($rows) . " missing_esi=$missingEsi empty_fits=$emptyFits\n";
	foreach (array_slice($rows, 0, 10) as $row) {
		echo "#{$row['rank']} {$row['shipName']} {$row['hash']} kills={$row['kills']} weighted={$row['weightedKills']} losses={$row['losses']} pilots={$row['pilotCount']}\n";
	}
	return;
}

if (sizeof($rows) == 0) {
	Util::out("fitkillers produced no rows for runID=$runID; keeping previous published run");
	$kvc->setex($cronKey, 3600, true);
	return;
}

saveFitKillerRows($rows, $runID);
$redis->set('zkb:fitKillers:runID', $runID);
$redis->set('zkb:fitKillers:meta', json_encode([
	'runID' => $runID,
	'updated' => $startTime,
	'days' => $days,
	'processed' => $processed,
	'victimLosses' => $victimLosses,
	'fittedLosses' => $fittedLosses,
	'matchedKills' => $matchedKills,
	'rows' => sizeof($rows),
	'missingEsi' => $missingEsi,
	'emptyFits' => $emptyFits,
]));
$redis->sadd("queueCacheTags", "fits");
$kvc->setex($cronKey, 84444, true);
Util::out("fitkillers runID=$runID days=$days processed=$processed victim_losses=$victimLosses fitted_losses=$fittedLosses matched_kills=$matchedKills rows=" . sizeof($rows));

function processFitKillerBatch($batch, &$stats, &$active, &$processed, &$victimLosses, &$fittedLosses, &$missingEsi, &$emptyFits, &$matchedKills)
{
    global $mdb;

    if (sizeof($batch) == 0) return;

    $lossIDs = [];
    foreach ($batch as $mail) {
        $victim = getFitKillerVictim($mail);
        if ($victim == null) continue;
        if ((int) @$victim['characterID'] <= 0 || (int) @$victim['shipTypeID'] <= 0) continue;
        if ((int) @$victim['groupID'] == 29) continue;
        if (!isFitKillerShip((int) $victim['shipTypeID'])) continue;
        $lossIDs[] = (int) $mail['killID'];
    }

    $esiByKillID = [];
    if (sizeof($lossIDs) > 0) {
        $cursor = $mdb->getCollection('esimails')->find(
            ['killmail_id' => ['$in' => $lossIDs]],
            ['projection' => ['_id' => 0, 'killmail_id' => 1, 'victim.items' => 1, 'victim.ship_type_id' => 1]]
        );
        foreach ($cursor as $esimail) {
            $esiByKillID[(int) $esimail['killmail_id']] = $esimail;
        }
    }

    foreach ($batch as $mail) {
        $processed++;
        $killID = (int) @$mail['killID'];
        $mailTime = fitKillerMailTime($mail);
        $attackers = fitKillerAttackerCount($mail);
        $weight = 1 / max(1, $attackers);

        foreach ((array) @$mail['involved'] as $involved) {
            if (@$involved['isVictim'] !== false) continue;

            $characterID = (int) @$involved['characterID'];
            $shipTypeID = (int) @$involved['shipTypeID'];
            if ($characterID <= 0 || $shipTypeID <= 0) continue;

            $key = "$characterID:$shipTypeID";
            if (!isset($active[$key])) continue;

            $fitKey = $active[$key]['fitKey'];
            $active[$key]['kills']++;
            $stats[$fitKey]['kills']++;
            $stats[$fitKey]['weightedKills'] += $weight;
            $stats[$fitKey]['iskDestroyed'] += (float) @$mail['zkb']['totalValue'];
            if (@$involved['finalBlow'] === true) $stats[$fitKey]['finalBlows']++;
            if (@$mail['solo'] === true || $attackers == 1) $stats[$fitKey]['soloKills']++;
            $matchedKills++;
        }

        $victim = getFitKillerVictim($mail);
        if ($victim == null) continue;

        $characterID = (int) @$victim['characterID'];
        $shipTypeID = (int) @$victim['shipTypeID'];
        if ($characterID <= 0 || $shipTypeID <= 0) continue;
        if ((int) @$victim['groupID'] == 29) continue;
        if (!isFitKillerShip($shipTypeID)) continue;

        $victimLosses++;
        $key = "$characterID:$shipTypeID";
        closeFitKillerLife($key, $stats, $active);

        $esimail = $esiByKillID[$killID] ?? null;
        if ($esimail == null || !isset($esimail['victim']['items'])) {
            $missingEsi++;
            unset($active[$key]);
            continue;
        }
        if ((int) @$esimail['victim']['ship_type_id'] != $shipTypeID) {
            unset($active[$key]);
            continue;
        }

        $fit = fitKillerSignature($shipTypeID, $esimail['victim']['items']);
        if ($fit == null) {
            $emptyFits++;
            unset($active[$key]);
            continue;
        }

        $fittedLosses++;
        $fitKey = $fit['hash'];
        if (!isset($stats[$fitKey])) {
            $stats[$fitKey] = [
                'hash' => $fitKey,
                'shipTypeID' => $shipTypeID,
                'shipName' => Info::getInfoField('typeID', $shipTypeID, 'name'),
                'l_shipName' => strtolower((string) Info::getInfoField('typeID', $shipTypeID, 'name')),
                'pip' => Info::getInfoField('typeID', $shipTypeID, 'pip'),
                'parts' => $fit['parts'],
                'losses' => 0,
                'activeLives' => 0,
                'bestLifeKills' => 0,
                'kills' => 0,
                'weightedKills' => 0,
                'finalBlows' => 0,
                'soloKills' => 0,
                'iskDestroyed' => 0,
                'pilots' => [],
                'sampleLossID' => $killID,
                'samplePilotID' => $characterID,
            ];
        }

        $stats[$fitKey]['losses']++;
        $stats[$fitKey]['pilots'][$characterID] = true;
        $active[$key] = ['fitKey' => $fitKey, 'kills' => 0, 'lossID' => $killID, 'lossTime' => $mailTime];
    }
}

function closeFitKillerLife($key, &$stats, &$active)
{
    if (!isset($active[$key])) return;

    $fitKey = $active[$key]['fitKey'];
    $kills = (int) $active[$key]['kills'];
    if ($kills > 0 && isset($stats[$fitKey])) {
        $stats[$fitKey]['activeLives']++;
        $stats[$fitKey]['bestLifeKills'] = max($stats[$fitKey]['bestLifeKills'], $kills);
    }
    unset($active[$key]);
}

function buildFitKillerRows($stats, $runID, $updated, $days, $processed, $victimLosses, $fittedLosses, $matchedKills, $minKills)
{
    $rows = [];
    foreach ($stats as $row) {
        if ((int) $row['kills'] < $minKills) continue;

        $pilotCount = sizeof($row['pilots']);
        $row['pilotCount'] = $pilotCount;
        $row['weightedKillsPerLoss'] = $row['weightedKills'] / max(1, $row['losses']);
        $row['killsPerLoss'] = $row['kills'] / max(1, $row['losses']);
        $row['fitSlots'] = fitKillerRows($row['parts']);
        $row['runID'] = $runID;
        $row['updated'] = $updated;
        $row['windowDays'] = $days;
        $row['processed'] = $processed;
        $row['victimLosses'] = $victimLosses;
        $row['fittedLosses'] = $fittedLosses;
        $row['matchedKills'] = $matchedKills;
        unset($row['pilots']);
        unset($row['parts']);
        $rows[] = $row;
    }

    usort($rows, function ($a, $b) {
        if ($a['kills'] == $b['kills']) {
            if ($a['weightedKills'] == $b['weightedKills']) return $a['losses'] <=> $b['losses'];
            return $b['weightedKills'] <=> $a['weightedKills'];
        }
        return $b['kills'] <=> $a['kills'];
    });

    foreach ($rows as $index => &$row) {
        $row['rank'] = $index + 1;
        $row['weightedKills'] = round($row['weightedKills'], 3);
        $row['weightedKillsPerLoss'] = round($row['weightedKillsPerLoss'], 3);
        $row['killsPerLoss'] = round($row['killsPerLoss'], 3);
        $row['iskDestroyed'] = round($row['iskDestroyed'], 2);
    }
    unset($row);

    return $rows;
}

function saveFitKillerRows($rows, $runID)
{
    global $mdb;

    $collection = $mdb->getCollection('fitkillers');
    $ops = [];
    foreach ($rows as $row) {
        $ops[] = [
            'replaceOne' => [
                ['hash' => $row['hash']],
                $row,
                ['upsert' => true],
            ],
        ];
        if (sizeof($ops) >= 1000) {
            $collection->bulkWrite($ops, ['ordered' => false]);
            $ops = [];
        }
    }
    if (sizeof($ops) > 0) $collection->bulkWrite($ops, ['ordered' => false]);

    $collection->deleteMany(['runID' => ['$ne' => $runID]]);
}

function optionInt($options, $key, $default, $min)
{
    if (!isset($options[$key]) || !is_numeric($options[$key])) return $default;
    return max($min, (int) $options[$key]);
}

function getFitKillerVictim($mail)
{
    foreach ((array) @$mail['involved'] as $involved) {
        if (@$involved['isVictim'] === true) return $involved;
    }
    return @$mail['involved'][0];
}

function fitKillerMailTime($mail)
{
    if (@$mail['dttm'] instanceof MongoDB\BSON\UTCDateTime) {
        return $mail['dttm']->toDateTime()->getTimestamp();
    }
    if (isset($mail['killTime'])) return strtotime($mail['killTime']);
    return 0;
}

function fitKillerAttackerCount($mail)
{
    if ((int) @$mail['attackerCount'] > 0) return (int) $mail['attackerCount'];

    $count = 0;
    foreach ((array) @$mail['involved'] as $involved) {
        if (@$involved['isVictim'] === false && (int) @$involved['characterID'] > 0) $count++;
    }
    return max(1, $count);
}

function isFitKillerShip($shipTypeID)
{
    static $shipCache = [];

    if (!isset($shipCache[$shipTypeID])) {
        $shipCache[$shipTypeID] = ((int) Info::getInfoField('typeID', $shipTypeID, 'categoryID') == 6);
    }

    return $shipCache[$shipTypeID];
}

function fitKillerSignature($shipTypeID, $items)
{
    $parts = [];
    foreach ((array) $items as $item) {
        $slot = fitKillerSlot((int) @$item['flag']);
        if ($slot == null) continue;

        $typeID = (int) @$item['item_type_id'];
        if ($typeID <= 0 || !isFitKillerType($typeID, $slot)) continue;

        $quantity = (int) @$item['quantity_destroyed'] + (int) @$item['quantity_dropped'];
        $quantity = max(1, $quantity);
        if (!isset($parts[$slot])) $parts[$slot] = [];
        $parts[$slot][$typeID] = ((int) @$parts[$slot][$typeID]) + $quantity;
    }

    if (sizeof($parts) == 0) return null;

    $tokens = [];
    foreach (fitKillerSlotOrder() as $slot) {
        if (!isset($parts[$slot])) continue;
        ksort($parts[$slot], SORT_NUMERIC);
        foreach ($parts[$slot] as $typeID => $quantity) {
            $tokens[] = "$slot:$typeID:$quantity";
        }
    }

    return [
        'hash' => substr(hash('sha256', $shipTypeID . '|' . implode('|', $tokens)), 0, 16),
        'parts' => $parts,
    ];
}

function fitKillerSlot($flag)
{
    if ($flag >= 11 && $flag <= 18) return 'Low';
    if ($flag >= 19 && $flag <= 26) return 'Mid';
    if ($flag >= 27 && $flag <= 34) return 'High';
    if ($flag >= 92 && $flag <= 98) return 'Rig';
    if ($flag >= 125 && $flag <= 132) return 'Sub';
    if ($flag == 87 || $flag == 158 || ($flag >= 159 && $flag <= 163)) return 'Drone';
    return null;
}

function isFitKillerType($typeID, $slot)
{
    static $categoryByTypeID = [];

    if (!isset($categoryByTypeID[$typeID])) {
        $categoryByTypeID[$typeID] = (int) Info::getInfoField('typeID', $typeID, 'categoryID');
    }

    if ($slot == 'Sub') return $categoryByTypeID[$typeID] == 32;
    if ($slot == 'Drone') return in_array($categoryByTypeID[$typeID], [18, 87]);
    return $categoryByTypeID[$typeID] == 7;
}

function fitKillerRows($parts)
{
    $rows = [];
    foreach (fitKillerSlotOrder() as $slot) {
        if (!isset($parts[$slot])) continue;

        $items = [];
        foreach ($parts[$slot] as $typeID => $quantity) {
            $items[] = [
                'typeID' => (int) $typeID,
                'name' => Info::getInfoField('typeID', (int) $typeID, 'name'),
                'quantity' => (int) $quantity,
            ];
        }
        $rows[] = ['slot' => $slot, 'items' => $items];
    }
    return $rows;
}

function fitKillerSlotOrder()
{
    return ['Low', 'Mid', 'High', 'Rig', 'Sub', 'Drone'];
}
