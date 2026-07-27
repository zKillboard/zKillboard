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
		Inferred::processFitKillerBatch($batch, $stats, $active, $processed, $victimLosses, $fittedLosses, $missingEsi, $emptyFits, $matchedKills);
		$batch = [];
	}
}
Inferred::processFitKillerBatch($batch, $stats, $active, $processed, $victimLosses, $fittedLosses, $missingEsi, $emptyFits, $matchedKills);

Inferred::closeFitKillerLives($stats, $active);

$rows = Inferred::buildFitKillerRows($stats, $runID, $startTime, $days, $processed, $victimLosses, $fittedLosses, $matchedKills, $minKills);

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
