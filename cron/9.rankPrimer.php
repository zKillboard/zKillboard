<?php

$types = ['allianceID', 'corporationID', 'characterID', 'shipTypeID', 'solarSystemID'];

$pids = [];
for ($i = 0; $i < sizeof($types); ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        exit();
    }
    if ($pid == 0) {
        $pids = [];
        break;
    }
    $pids[] = $pid;
}

require_once "../init.php";

if ($redis->get("zkb:hasPrimed") == 1) exit();

$today = date('Ymd');

if ($i != sizeof($types)) {
    $type = $types[$i];

    $top100 = $redis->zRange("tq:ranks:weekly:$type", 0, 99);
    $type = str_replace("ID", "", $type);
    $type = str_replace("Type", "", $type);

    foreach ($top100 as $entityID)
    {
        $topParameters = [$type => [$entityID], 'limit' => 10, 'kills' => 1, 'pastSeconds' => 604800];
        $topParameters['cacheTime'] = 86400;

        //echo "$type $entityID\n";
        Util::getData("http://127.0.0.1/$type/$entityID/", 0);
    }

    $redis->setex("zkb:hasPrimed", 3600, 1);
}
$status = [];
foreach ($pids as $pid) pcntl_waitpid($pid,$status, 0);
