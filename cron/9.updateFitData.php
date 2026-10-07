<?php

require_once '../init.php';

global $redis;

$runKey = 'zkb:updateFitData:run:' . gmdate('Y-m-d');
if ($redis->get($runKey) !== false) exit();
$redis->setex($runKey, 3333, 'attempting');

exec('command -v node', $output, $status);
if ($status !== 0) {
    Util::zout('Fitting data update failed: Node.js is unavailable.');
    exit();
}

passthru('node ' . escapeshellarg(__DIR__ . '/../setup/updateFitData.mjs'), $status);
if ($status !== 0) Util::zout("Fitting data update failed with status $status.");
$redis->setex($runKey, 86400, 'complete');
