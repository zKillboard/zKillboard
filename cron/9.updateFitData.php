<?php

require_once '../init.php';

global $redis;

$runKey = 'zkb:updateFitData:run:' . gmdate('Y-m-d');
if ($redis->get($runKey) !== false) exit();
$redis->setex($runKey, 3333, 'attempting');

$node = '/usr/local/bin/node';
if (!is_executable($node)) {
    Util::zout("Fitting data update failed: $node is unavailable.");
    exit();
}

passthru(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/../setup/updateFitData.mjs'), $status);
if ($status !== 0) Util::zout("Fitting data update failed with status $status.");
$redis->setex($runKey, 86400, 'complete');
