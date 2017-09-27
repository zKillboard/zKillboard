<?php

require_once 'init.php';

while (true) {
    $agents = [];
    $uris = [];
    $ips = [];
    for ($i = 0; $i <= 6; ++$i) {
        $ts = floor((time() - ($i * 60)) / 60);
        addAll($agents, $redis->hGetAll("analytics:agent:$ts"));
        addAll($uris, $redis->hGetAll("analytics:uri:$ts"));
        addAll($ips, $redis->hGetAll("analytics:ip:$ts"));
    }
    system('clear');
    show10($ips);
    show10($uris);
    show10($agents);
    sleep(5);
}

function addAll(&$array, &$map)
{
    foreach ($map as $k => $v) {
        @$array[$k] += $v;
    }
}

function show10(&$array)
{
    arsort($array);
    $i = 10;
    while (sizeof($array) > 10 && $i > 0) {
        reset($array);
        $key = key($array);
        $value = $array[$key];
        array_shift($array);
        echo "$value $key\n";
        --$i;
    }
    echo "\n";
}
