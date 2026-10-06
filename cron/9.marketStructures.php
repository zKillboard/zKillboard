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
    if ($redis->get('zkb:marketStructures:hash') === $hash) {
        $redis->setex($runKey, 172800, 'unchanged');
        exit();
    }

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
    $redis->del('market:data:v6');
    Util::out('Updated ' . number_format(count($structures)) . ' market structure names.');
} catch (Throwable $error) {
    Util::zout('Market structure update failed: ' . $error->getMessage());
}
