<?php

require_once '../init.php';

global $mdb, $redis;

$day = gmdate('Y-m-d');
$runKey = "zkb:marketStructures:run:$day";
if ($redis->get($runKey) !== false) exit();

$url = 'https://static.adam4eve.eu/IDs/playerStructure_IDs.csv';
$client = new \GuzzleHttp\Client([
    'connect_timeout' => 15,
    'timeout' => 120,
    'headers' => ['User-Agent' => 'zkillboard.com market structure updater'],
]);

try {
    $csv = (string) $client->get($url)->getBody();
    if ($csv === '') throw new RuntimeException('The structure CSV was empty.');

    $hash = hash('sha256', $csv);
    $unchanged = $redis->get('zkb:marketStructures:hash') === $hash;

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ';', '"', '\\');
    if (!is_array($header)) throw new RuntimeException('The structure CSV header was invalid.');
    $header = array_map(fn($value) => ltrim(trim((string) $value), "\xEF\xBB\xBF"), $header);
    $idColumn = array_search('structureID', $header, true);
    $nameColumn = array_search('name', $header, true);
    if ($idColumn === false || $nameColumn === false) throw new RuntimeException('The structure CSV is missing structureID or name.');

    $structures = [];
    while (($row = fgetcsv($stream, 0, ';', '"', '\\')) !== false) {
        $id = (int) ($row[$idColumn] ?? 0);
        $name = trim((string) ($row[$nameColumn] ?? ''), " \t\n\r\0\x0B\"");
        if ($id > 0 && $name !== '') $structures[$id] = $name;
    }
    fclose($stream);
    if (count($structures) < 1000) throw new RuntimeException('The structure CSV contained too few valid rows.');

    $systems = [];
    foreach ($mdb->find('information', ['type' => 'solarSystemID'], [], null, ['_id' => 0, 'id' => 1, 'name' => 1, 'regionID' => 1]) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) $systems[(string) $id] = ['name' => (string) ($row['name'] ?? ''), 'region_id' => (int) ($row['regionID'] ?? 0)];
    }

    $locations = [];
    foreach ($mdb->getCollection('sde_npcStations')->find([], ['projection' => ['_id' => 0, '_key' => 1, 'name.en' => 1]]) as $row) {
        $id = (int) ($row['_key'] ?? 0);
        $name = (string) ($row['name']['en'] ?? '');
        if ($id > 0 && $name !== '') $locations[(string) $id] = $name;
    }
    foreach ($structures as $id => $name) $locations[(string) $id] = $name;

    $regions = [];
    foreach ($mdb->find('information', ['type' => 'regionID'], ['l_name' => 1], null, ['_id' => 0, 'id' => 1, 'name' => 1]) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id === 10000019 || ($id >= 11000000 && $id < 13000000)) continue;
        $regions[] = ['id' => $id, 'name' => (string) ($row['name'] ?? '')];
    }

    $files = [
        __DIR__ . '/../public/data/market-systems.json' => json_encode(['systems' => $systems, 'locations' => $locations], JSON_UNESCAPED_SLASHES),
        __DIR__ . '/../public/data/market-regions.json' => json_encode($regions, JSON_UNESCAPED_SLASHES),
    ];
    foreach ($files as $path => $json) {
        $temporary = $path . '.tmp';
        if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to update ' . basename($path) . '.');
        }
    }

    if ($unchanged) {
        $redis->setex($runKey, 172800, 'unchanged');
        exit();
    }

    $collection = $mdb->getCollection('marketStructures');
    $existing = [];
    foreach ($collection->find([], ['projection' => ['_id' => 0, 'structureID' => 1, 'name' => 1]]) as $row) {
        $existing[(int) (string) ($row['structureID'] ?? 0)] = (string) ($row['name'] ?? '');
    }

    $operations = [];
    $updated = gmdate('c');
    foreach ($structures as $id => $name) {
        if (($existing[$id] ?? null) === $name) {
            unset($existing[$id]);
            continue;
        }
        $operations[] = ['updateOne' => [
            ['structureID' => $id],
            ['$set' => ['structureID' => $id, 'name' => $name, 'updated' => $updated]],
            ['upsert' => true],
        ]];
        unset($existing[$id]);
        if (count($operations) >= 1000) {
            $collection->bulkWrite($operations, ['ordered' => false]);
            $operations = [];
        }
    }
    if ($operations) $collection->bulkWrite($operations, ['ordered' => false]);

    foreach (array_chunk(array_keys($existing), 1000) as $ids) {
        $collection->deleteMany(['structureID' => ['$in' => $ids]]);
    }

    $redis->set('zkb:marketStructures:hash', $hash);
    $redis->setex($runKey, 172800, (string) count($structures));
    Util::out('Updated ' . number_format(count($structures)) . ' market structure names.');
} catch (Throwable $error) {
    Util::zout('Market structure update failed: ' . $error->getMessage());
}
