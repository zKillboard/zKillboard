<?php

require_once "../init.php";

global $mdb;

$sourceCollections = [
    'mapAsteroidBelts' => ['idKeys' => ['asteroidBeltID', 'typeID', 'solarSystemID']],
    'mapMoons' => ['idKeys' => ['moonID', 'typeID', 'solarSystemID']],
    'mapPlanets' => ['idKeys' => ['planetID', 'typeID', 'solarSystemID']],
    'mapRegions' => ['idKeys' => ['regionID']],
    'mapConstellations' => ['idKeys' => ['constellationID', 'regionID']],
    'mapSolarSystems' => ['idKeys' => ['solarSystemID', 'regionID', 'constellationID', 'starID']],
    'mapStargates' => ['idKeys' => ['stargateID', 'solarSystemID', 'typeID']],
    'mapStars' => ['idKeys' => ['starID', 'solarSystemID', 'typeID']],
    'npcStations' => ['idKeys' => ['stationID', 'solarSystemID', 'typeID']],
];

$targetCollection = $mdb->getCollection('locations_calced');
try {
    $targetCollection->createIndex(
        ['sourceCollection' => 1, 'id' => 1],
        ['unique' => true, 'name' => 'sourceCollection_id_unique']
    );
} catch (Exception $ex) {
    $msg = (string) $ex->getMessage();
    $code = (int) $ex->getCode();
    $isIndexConflict = (
        stripos($msg, 'already exists') !== false ||
        stripos($msg, 'same name as the requested index') !== false ||
        stripos($msg, 'equivalent index already exists') !== false ||
        $code === 85 ||
        $code === 86
    );
    if (!$isIndexConflict) throw $ex;
}

function getNormalizedPosition($row)
{
    if (isset($row['position']) && is_array($row['position'])) {
        $x = $row['position']['x'] ?? null;
        $y = $row['position']['y'] ?? null;
        $z = $row['position']['z'] ?? null;
        if (is_numeric($x) && is_numeric($y) && is_numeric($z)) {
            return ['x' => (float) $x, 'y' => (float) $y, 'z' => (float) $z];
        }
    }

    $x = $row['x'] ?? null;
    $y = $row['y'] ?? null;
    $z = $row['z'] ?? null;
    if (is_numeric($x) && is_numeric($y) && is_numeric($z)) {
        return ['x' => (float) $x, 'y' => (float) $y, 'z' => (float) $z];
    }

    return null;
}

function getSourceKey($row, $idKeys)
{
    if (isset($row['key']) && $row['key'] !== null && $row['key'] !== '') return $row['key'];
    if (isset($row['_key']) && $row['_key'] !== null && $row['_key'] !== '') return $row['_key'];

    foreach ($idKeys as $idKey) {
        if (isset($row[$idKey]) && $row[$idKey] !== null) return $row[$idKey];
    }

    return null;
}

function getEntityID($row, $idKeys)
{
    foreach ($idKeys as $idKey) {
        if (isset($row[$idKey]) && is_numeric($row[$idKey])) return (int) $row[$idKey];
    }
    return null;
}

function getSolarSystemID($row, $source)
{
    if (isset($row['solarSystemID']) && is_numeric($row['solarSystemID'])) {
        return (int) $row['solarSystemID'];
    }

    if ($source === 'mapSolarSystems') {
        $key = $row['key'] ?? $row['_key'] ?? null;
        if (is_numeric($key)) return (int) $key;
    }

    return null;
}

$stats = [];
$totalUpserts = 0;
$totalSkipped = 0;

foreach ($sourceCollections as $source => $sourceConfig) {
	Util::out("Processing source collection: $source");
    $fullCollection = "sde_$source";
    $idKeys = $sourceConfig['idKeys'] ?? [];
    $processed = 0;
    $upserted = 0;
    $skipped = 0;
    $bulkOps = [];
    $batchSize = 1000;

    $rows = $mdb->find($fullCollection);
    foreach ($rows as $row) {
        $processed++;

        if ($source === 'mapStars') {
            $position = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
        } else {
            $position = getNormalizedPosition($row);
        }
        if ($position === null) {
            $skipped++;
            continue;
        }

        $sourceKey = getSourceKey($row, $idKeys);
        if ($sourceKey === null) {
            $skipped++;
            continue;
        }

        $doc = [
            'sourceCollection' => $fullCollection,
            'type' => $source,
            'id' => $sourceKey,
            'entityID' => getEntityID($row, $idKeys),
            'solar_system_id' => getSolarSystemID($row, $source),
            'name' => ($row['name'] ?? "$source $sourceKey"),
            'position' => $position,
            'updated' => $mdb->now(),
        ];

        $bulkOps[] = [
            'updateOne' => [
                ['sourceCollection' => $fullCollection, 'id' => $sourceKey],
                ['$set' => $doc],
                ['upsert' => true],
            ],
        ];

        if (sizeof($bulkOps) >= $batchSize) {
            $targetCollection->bulkWrite($bulkOps, ['ordered' => false]);
            $upserted += sizeof($bulkOps);
            $bulkOps = [];
        }
    }

    if (sizeof($bulkOps) > 0) {
        $targetCollection->bulkWrite($bulkOps, ['ordered' => false]);
        $upserted += sizeof($bulkOps);
    }

    $stats[$fullCollection] = ['processed' => $processed, 'upserted' => $upserted, 'skipped' => $skipped];
    $totalUpserts += $upserted;
    $totalSkipped += $skipped;
}

echo "locations_calced update complete\n";
foreach ($stats as $collection => $stat) {
    echo "$collection: processed={$stat['processed']} upserted={$stat['upserted']} skipped={$stat['skipped']}\n";
}
echo "TOTAL upserted=$totalUpserts skipped=$totalSkipped\n";
