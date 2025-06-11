<?php

require_once "../init.php";

$redis->select(0);
$keys = $redis->keys("*");

$mem = [];
$totalSize = 0;
foreach ($keys as $key) {
    $ttl = $redis->ttl($key);
    if ($ttl >= 0) continue;
    $size = $redis->rawCommand('memory', 'usage', $key);
    $split = split(":", $key);
    $memkey = $split[0] ; //  . ":" . $split[1];
    if (!isset($mem[$memkey])) $mem[$memkey] = 0;
    $mem[$memkey] += $size;
    $totalSize += $size;
}
asort($mem);
$a = ['', 'kb', 'mb', 'gb'];
foreach ($mem as $key=>$value) {
    $c = 0;
    while ($value >= 1024 && $c < sizeof($a)) {
        $value = $value / 1024;
        $c++;
    }
    echo "$key " . round($value, 2) . $a[$c] . "\n";
}

echo "\n total size $totalSize \n";
