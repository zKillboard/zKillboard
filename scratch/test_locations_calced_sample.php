<?php

require_once "../init.php";

global $mdb;

$sampleSize = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (int) $argv[1]) : 1000;
$lookbackDays = isset($argv[2]) && is_numeric($argv[2]) ? max(1, (int) $argv[2]) : 365;
$maxMismatchDetails = isset($argv[3]) && is_numeric($argv[3]) ? max(0, (int) $argv[3]) : 25;
$progressInterval = isset($argv[4]) && is_numeric($argv[4]) ? max(1, (int) $argv[4]) : 100;

$sinceEpoch = time() - ($lookbackDays * 86400);
$since = new MongoDB\BSON\UTCDateTime($sinceEpoch * 1000);

$pipeline = [
    ['$match' => [
        'dttm' => ['$gte' => $since],
        'locationID' => ['$exists' => true],
        'system.solarSystemID' => ['$exists' => true],
    ]],
    ['$sample' => ['size' => $sampleSize]],
    ['$project' => [
        '_id' => 0,
        'killID' => 1,
        'locationID' => 1,
        'system.solarSystemID' => 1,
    ]],
];

$cursor = $mdb->getCollection('killmails')->aggregate($pipeline, ['allowDiskUse' => true]);

echo "Starting locations-calced validation"
    . " sample_size=$sampleSize"
    . " lookback_days=$lookbackDays"
    . " progress_interval=$progressInterval\n";

$totalSampled = 0;
$missingEsi = 0;
$missingPosition = 0;
$nullComputed = 0;
$compared = 0;
$matches = 0;
$mismatches = 0;
$mismatchExamples = [];

foreach ($cursor as $row) {
    $totalSampled++;

    $killID = (int) ($row['killID'] ?? 0);
    $expectedLocationID = (int) ($row['locationID'] ?? 0);
    $solarSystemID = (int) ($row['system']['solarSystemID'] ?? 0);

    if ($killID <= 0 || $expectedLocationID <= 0 || $solarSystemID <= 0) {
        continue;
    }

    $esimail = $mdb->findDoc('esimails', ['killmail_id' => $killID], [], ['victim.position' => 1]);
    if ($esimail == null) {
        $missingEsi++;
        continue;
    }

    $position = $esimail['victim']['position'] ?? null;
    if (!is_array($position) || !isset($position['x']) || !isset($position['y']) || !isset($position['z'])) {
        $missingPosition++;
        continue;
    }

    $computedLocationID = Info::getLocationID($solarSystemID, $position);
    if ($computedLocationID === null) {
        $nullComputed++;
        continue;
    }

    $compared++;
    if ((int) $computedLocationID === $expectedLocationID) {
        $matches++;
    } else {
        $mismatches++;
        if (count($mismatchExamples) < $maxMismatchDetails) {
            $mismatchExamples[] = [
                'killID' => $killID,
                'solarSystemID' => $solarSystemID,
                'expectedLocationID' => $expectedLocationID,
                'computedLocationID' => (int) $computedLocationID,
                'position' => [
                    'x' => (float) $position['x'],
                    'y' => (float) $position['y'],
                    'z' => (float) $position['z'],
                ],
            ];
        }
    }

    if ($totalSampled % $progressInterval === 0) {
        $runningRate = $compared > 0 ? round(($matches / $compared) * 100, 2) : 0;
        echo "progress"
            . " sampled=$totalSampled/$sampleSize"
            . " compared=$compared"
            . " matches=$matches"
            . " mismatches=$mismatches"
            . " missing_esi=$missingEsi"
            . " missing_position=$missingPosition"
            . " computed_null=$nullComputed"
            . " match_rate={$runningRate}%\n";
    }
}

$matchRate = $compared > 0 ? round(($matches / $compared) * 100, 2) : 0;
$mismatchRate = $compared > 0 ? round(($mismatches / $compared) * 100, 2) : 0;

echo "Locations-calced validation complete\n";
echo "sample_size_requested=$sampleSize lookback_days=$lookbackDays\n";
echo "sampled=$totalSampled compared=$compared matches=$matches mismatches=$mismatches\n";
echo "match_rate={$matchRate}% mismatch_rate={$mismatchRate}%\n";
echo "missing_esi=$missingEsi missing_position=$missingPosition computed_null=$nullComputed\n";

if (count($mismatchExamples) > 0) {
    echo "\nMismatch examples (up to $maxMismatchDetails):\n";
    foreach ($mismatchExamples as $example) {
        echo json_encode($example, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
