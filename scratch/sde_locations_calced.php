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

$locations_calced = $mdb->getCollection('locations_calced');
$information = $mdb->getCollection('information');

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

function getRadius($row)
{
    global $mdb;

    if (isset($row['radius']) && is_numeric($row['radius'])) return (float) $row['radius'];

    $typeID = $row['typeID'] ?? null;
    if (!is_numeric($typeID)) return null;

    $radius = $mdb->findField('information', 'radius', ['type' => 'typeID', 'id' => (int) $typeID, 'cacheTime' => 3600]);
    if (!is_numeric($radius)) return null;
    return (float) $radius;
}

function clamp($value, $min, $max)
{
    if ($value < $min) return $min;
    if ($value > $max) return $max;
    return $value;
}

// Warpin location formulas are obtained from https://developers.eveonline.com/docs/guides/useful-formulae/

function calcLargeObjectWarpPoint($x, $y, $z, $radius)
{
    $warpX = $x + (($radius + 5000000) * cos($radius));
    $warpY = $y + (1.3 * $radius) - 7500;
    $warpZ = $z - (($radius + 5000000) * sin($radius));
    return ['x' => (float) $warpX, 'y' => (float) $warpY, 'z' => (float) $warpZ];
}

function calcSunWarpPoint($radius)
{
    $warpX = ($radius + 100000) * cos($radius);
    $warpY = 0.2 * $radius;
    $warpZ = -($radius + 100000) * sin($radius);
    return ['x' => (float) $warpX, 'y' => (float) $warpY, 'z' => (float) $warpZ];
}

function calcPlanetWarpPoint($planetID, $x, $y, $z, $radius)
{
    mt_srand((int) $planetID);
    $mt = mt_rand() / mt_getrandmax();
    $j = ($mt - 1) / 3;

    $sRaw = 20 * pow(((10 * log10($radius / 1000000) - 39) / 40), 20) + 0.5;
    $s = clamp($sRaw, 0.5, 10.5);
    $d = $radius * ($s + 1) + 1000000;

    $xzLength = sqrt(pow($x, 2) + pow($z, 2));
    if ($xzLength <= 0) return null;

    $xSign = ($x >= 0 ? 1 : -1);
    $ratio = ($xSign * $z) / $xzLength;
    $ratio = clamp($ratio, -1, 1);
    $theta = asin($ratio) + $j;

    $warpX = $x + sin($theta) * $d;
    $warpY = $y + $radius * sin($j) / 2;
    $warpZ = $z - cos($theta) * $d;

    return ['x' => (float) $warpX, 'y' => (float) $warpY, 'z' => (float) $warpZ];
}

function getWarpPoint($source, $row, $position, $radius, $entityID)
{
    global $mdb;

    if ($entityID !== null) {
        $celestial = $mdb->findDoc('celestials', ['CelestialID' => (int) $entityID], ['WarpX' => 1, 'WarpY' => 1, 'WarpZ' => 1]);
        if (
            $celestial != null &&
            isset($celestial['WarpX']) && is_numeric($celestial['WarpX']) &&
            isset($celestial['WarpY']) && is_numeric($celestial['WarpY']) &&
            isset($celestial['WarpZ']) && is_numeric($celestial['WarpZ'])
        ) {
            return [
                'x' => (float) $celestial['WarpX'],
                'y' => (float) $celestial['WarpY'],
                'z' => (float) $celestial['WarpZ'],
            ];
        }
    }

    if (!is_numeric($radius)) return null;
    $radius = (float) $radius;
    if ($radius <= 0) return null;

    $x = (float) $position['x'];
    $y = (float) $position['y'];
    $z = (float) $position['z'];

    if ($source === 'mapStars') {
        return calcSunWarpPoint($radius);
    }

    if ($source === 'mapPlanets') {
        $planetID = $row['planetID'] ?? $row['key'] ?? $row['_key'] ?? null;
        if (!is_numeric($planetID)) return null;
        return calcPlanetWarpPoint((int) $planetID, $x, $y, $z, $radius);
    }

    if ($source !== 'mapStars' && $source !== 'mapPlanets' && $source != "npcStations" && $radius >= 90000) {
        return calcLargeObjectWarpPoint($x, $y, $z, $radius);
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
    $locations_bulkOps = [];
	$information_bulkOps = [];
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

        $entityID = getEntityID($row, $idKeys);
        $radius = getRadius($row);
        $warp = getWarpPoint($source, $row, $position, $radius, $entityID);

        $doc = [
            'sourceCollection' => $fullCollection,
            'type' => $source,
            'id' => $sourceKey,
            'entityID' => $entityID,
            'solar_system_id' => getSolarSystemID($row, $source),
            'name' => ((string) ($row['name']['en'] ?? "$source $sourceKey")),
            'position' => $position,
            'Radius' => (is_numeric($radius) ? (float) $radius : null),
            'updated' => $mdb->now(),
        ];

        if (is_array($warp)) {
            $doc['WarpX'] = $warp['x'];
            $doc['WarpY'] = $warp['y'];
            $doc['WarpZ'] = $warp['z'];
        }

        $locations_bulkOps[] = [
            'updateOne' => [
                ['sourceCollection' => $fullCollection, 'id' => $sourceKey],
                ['$set' => $doc],
                ['upsert' => true],
            ],
        ];

		$type = "locationID";
		switch ($source) {
			case 'mapRegions':
				$type = 'regionID';
				break;
			case 'mapConstellations':
				$type = 'constellationID';
				break;
			case 'mapSolarSystems':
				$type = 'solarSystemID';
				break;
			case 'mapStars':
				$doc['name'] .= " (Star)";
				break;
		}

		$info_doc = [
			'type' => $type,
			'id' => $sourceKey,
			'name' => $doc['name'],
			'l_name' => strtolower($doc['name']),
		];

		if ($type == "locationID" && isset($row['solarSystemID'])) {
			$info_doc['solarSystemID'] = $row['solarSystemID'];
		}

		$information_bulkOps[] = [
			'updateOne' => [
				['type' => $type, 'id' => $sourceKey],
				['$set' => $info_doc],
				['upsert' => true],
			],
		];

        if (sizeof($locations_bulkOps) >= $batchSize) {
            $locations_calced->bulkWrite($locations_bulkOps, ['ordered' => false]);
            $upserted += sizeof($locations_bulkOps);
            $locations_bulkOps = [];

			if (sizeof($information_bulkOps) >= $batchSize) {
				$information->bulkWrite($information_bulkOps, ['ordered' => false]);
				$information_bulkOps = [];
			}
        }
    }

    if (sizeof($locations_bulkOps) > 0) {
        $locations_calced->bulkWrite($locations_bulkOps, ['ordered' => false]);
        $upserted += sizeof($locations_bulkOps);
    }

    if (sizeof($information_bulkOps) > 0) {
        $information->bulkWrite($information_bulkOps, ['ordered' => false]);
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
