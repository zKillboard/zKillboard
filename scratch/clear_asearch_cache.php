<?php

require_once __DIR__ . '/../config.php';

$host = '127.0.0.1';
foreach ($argv as $arg) {
    if (strpos($arg, '--host=') === 0) $host = substr($arg, 7);
}
$dryRun = !in_array('--delete', $argv, true);
$patterns = [
    'asearch:*',
    'groups:asearch:*',
    'labels:asearch:*',
    'distincts:asearch:*',
    'Stats::getTop:r:*',
    'Stats::getSums:q:*',
    'Stats::getDistincts:*'
];

$total = 0;
foreach ($patterns as $pattern) {
    $count = 0;
    $batch = [];
    $cmd = 'redis-cli -h ' . escapeshellarg($host) . ' --scan --pattern ' . escapeshellarg($pattern);
    $pipe = popen($cmd, 'r');
    if ($pipe === false) {
        echo "$pattern: unable to scan\n";
        continue;
    }

    while (($key = fgets($pipe)) !== false) {
        $key = trim($key);
        if ($key == '') continue;
        $count++;
        $batch[] = $key;
        if (sizeof($batch) >= 500) {
            if (!$dryRun) redisCliDel($host, $batch);
            $batch = [];
        }
    }
    pclose($pipe);

    if (sizeof($batch) > 0 && !$dryRun) redisCliDel($host, $batch);
    $total += $count;
    echo "$pattern: $count\n";
}

echo ($dryRun ? "Dry run" : "Deleted") . ": $total keys\n";
if ($dryRun) echo "Run with --delete to remove these keys.\n";

function redisCliDel($host, $keys)
{
    $cmd = 'redis-cli -h ' . escapeshellarg($host) . ' del';
    foreach ($keys as $key) $cmd .= ' ' . escapeshellarg($key);
    exec($cmd);
}
