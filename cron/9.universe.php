<?php

require_once '../init.php';

if ($kvc->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:sdeUniverseCheck")) exit();
$redis->setex("zkb:sdeUniverseCheck", 900, "true");

try {
    $manifest = SDE::manifest();
    $tables = ['categories', 'groups', 'types', 'typeDogma', 'mapRegions', 'mapConstellations', 'mapSolarSystems', 'mapStars'];
    $versions = [];
    foreach ($tables as $table) {
        if (empty($manifest['tables'][$table]['version'])) throw new RuntimeException("Missing SDE table $table");
        $versions[$table] = $manifest['tables'][$table]['version'];
    }
    $version = hash('sha256', json_encode($versions));
    if ($kvc->get("zkb:universeSdeVersion") == $version && $kvc->get("zkb:universeLoaded") == "true") exit();

    Util::out("Loading universe from SDE " . $manifest['release']);
    $categories = [];
    foreach (SDE::rows($manifest, 'categories') as $row) {
        $categories[$row['categoryID']] = ['category_id' => $row['categoryID'], 'name' => $row['name']['en'], 'published' => $row['published'], 'groups' => []];
    }
    $groups = [];
    foreach (SDE::rows($manifest, 'groups') as $row) {
        if (!isset($categories[$row['categoryID']])) throw new RuntimeException('Missing SDE category');
        $categories[$row['categoryID']]['groups'][] = $row['groupID'];
        $groups[$row['groupID']] = ['group_id' => $row['groupID'], 'category_id' => $row['categoryID'], 'categoryID' => $row['categoryID'], 'name' => $row['name']['en'], 'published' => $row['published'] ?? false, 'types' => []];
    }

    // Keep dogma compact in memory; decode only the type being imported.
    $dogma = [];
    foreach (SDE::rows($manifest, 'typeDogma') as $row) {
        $dogma[$row['typeID']] = json_encode($row);
    }
    $distinctTypeIDs = array_fill_keys($mdb->getCollection('killmails')->distinct('involved.shipTypeID'), true);
    foreach (SDE::rows($manifest, 'types') as $row) {
        $id = $row['typeID'];
        $groupID = $row['groupID'];
        if (!isset($groups[$groupID])) throw new RuntimeException("Missing SDE group for type $id");
        $groups[$groupID]['types'][] = $id;
        $type = [
            'type_id' => $id, 'name' => $row['name']['en'], 'description' => $row['description']['en'] ?? '',
            'group_id' => $groupID, 'groupID' => $groupID, 'categoryID' => $groups[$groupID]['categoryID'],
            'published' => ($row['published'] ?? false) || ($id > 0 && isset($distinctTypeIDs[$id])),
            'portionSize' => $row['portionSize'] ?? 1, 'portion_size' => $row['portionSize'] ?? 1,
            'dogma_attributes' => [], 'dogma_effects' => [],
        ];
        foreach (['capacity' => 'capacity', 'mass' => 'mass', 'volume' => 'volume', 'radius' => 'radius', 'packagedVolume' => 'packaged_volume', 'marketGroupID' => 'market_group_id', 'graphicID' => 'graphic_id', 'iconID' => 'icon_id', 'raceID' => 'race_id'] as $source => $field) {
            $type[$field] = $row[$source] ?? null;
        }
        $attributes = isset($dogma[$id]) ? json_decode($dogma[$id], true, 512, JSON_THROW_ON_ERROR) : [];
        foreach ($attributes['dogmaAttributes'] ?? [] as $attribute) {
            $type['dogma_attributes'][] = ['attribute_id' => $attribute['attributeID'], 'value' => $attribute['value']];
        }
        foreach ($attributes['dogmaEffects'] ?? [] as $effect) {
            $type['dogma_effects'][] = ['effect_id' => $effect['effectID'], 'is_default' => $effect['isDefault']];
        }
        $mdb->insertUpdate("information", ['type' => 'typeID', 'id' => $id], $type);
    }
    unset($dogma);
    foreach ($groups as $id => $group) $mdb->insertUpdate("information", ['type' => 'groupID', 'id' => $id], $group);
    foreach ($categories as $id => $category) $mdb->insertUpdate("information", ['type' => 'categoryID', 'id' => $id], $category);

    foreach (SDE::rows($manifest, 'mapRegions') as $row) {
        $id = $row['regionID'];
        $region = ['region_id' => $id, 'name' => $row['name']['en'], 'constellations' => $row['constellationIDs'], 'description' => $row['description']['en'] ?? ''];
        $mdb->insertUpdate("information", ['type' => 'regionID', 'id' => $id], $region);
    }
    foreach (SDE::rows($manifest, 'mapConstellations') as $row) {
        $id = $row['constellationID'];
        $constellation = ['constellation_id' => $id, 'name' => $row['name']['en'], 'region_id' => $row['regionID'], 'regionID' => $row['regionID'], 'systems' => $row['solarSystemIDs'], 'position' => $row['position']];
        $mdb->insertUpdate("information", ['type' => 'constellationID', 'id' => $id], $constellation);
    }
    foreach (SDE::rows($manifest, 'mapSolarSystems') as $row) {
        $id = $row['solarSystemID'];
        $name = ($id >= 32000000 && $id < 33000000) ? Info::getMangledSystemName($id, 0) : $row['name']['en'];
        $system = [
            'system_id' => $id, 'name' => $name, 'position' => $row['position'],
            'constellation_id' => $row['constellationID'], 'constellationID' => $row['constellationID'], 'regionID' => $row['regionID'],
            'security_status' => $row['securityStatus'], 'secStatus' => $row['securityStatus'],
            'security_class' => $row['securityClass'] ?? null, 'secClass' => $row['securityClass'] ?? null,
            'star_id' => $row['starID'] ?? null, 'stargates' => $row['stargateIDs'],
        ];
        $mdb->insertUpdate("information", ['type' => 'solarSystemID', 'id' => $id], $system);
    }
    foreach (SDE::rows($manifest, 'mapStars') as $row) {
        $id = $row['starID'];
        $star = ['type_id' => $row['typeID'], 'solar_system_id' => $row['solarSystemID'], 'radius' => $row['radius']];
        foreach (['age' => 'age', 'life' => 'life', 'luminosity' => 'luminosity', 'spectralClass' => 'spectral_class', 'temperature' => 'temperature'] as $source => $field) {
            $star[$field] = $row['statistics'][$source] ?? null;
        }
        $mdb->insertUpdate("information", ['type' => 'starID', 'id' => $id], $star);
    }

    $kvc->set("zkb:universeSdeVersion", $version);
    $kvc->set("zkb:universeLoaded", "true");
} catch (Throwable $ex) {
    Util::out("SDE universe import failed: " . $ex->getMessage());
    exit(1);
}
