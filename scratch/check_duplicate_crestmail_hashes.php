<?php

require_once __DIR__ . '/../init.php';

$guzzler = new Guzzler(10);

$cursor = $mdb->getCollection('crestmails')->aggregate([
    ['$match' => ['processed' => true]],
    ['$group' => [
        '_id' => '$killID',
        'count' => ['$sum' => 1],
        'hashes' => ['$push' => '$hash'],
    ]],
    ['$match' => ['count' => ['$gt' => 1]]],
    ['$sort' => ['_id' => 1]],
], ['allowDiskUse' => true]);

printf("%-12s %-40s %-6s %s\n", 'killID', 'hash', 'status', 'result');

foreach ($cursor as $row) {
    $killID = (int) $row['_id'];

    foreach ($row['hashes'] as $hash) {
        $hash = (string) $hash;
        $url = rtrim($esiServer, '/') . "/killmails/$killID/" . rawurlencode($hash) . '/';
        $guzzler->call($url, 'hashCheckSuccess', 'hashCheckFailure', ['killID' => $killID, 'hash' => $hash]);
    }
	$guzzler->finish();
}

$guzzler->finish();

function hashCheckSuccess(&$guzzler, &$params, &$content)
{
    $status = (int) $params['STATUS_CODE'];
    outputHashCheck($params, $status, $status >= 200 && $status < 300 ? 'valid' : 'inconclusive');
}

function hashCheckFailure($guzzler, $params, $ex)
{
    $status = (int) $params['STATUS_CODE'];
    outputHashCheck($params, $status, $status === 404 ? 'invalid' : 'inconclusive');
}

function outputHashCheck($params, $status, $result)
{
    printf("%-12d %-40s %-6d %s\n", $params['killID'], $params['hash'], $status, $result);
}
