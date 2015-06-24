#!/usr/bin/php5
<?php

require_once 'init.php';

echo "gathering information....\n";
$redisQueues = [];
$priorKillLog = 0;

$deltaArray = [];

while (true) {
    $infoArray = [];

    $queues = $redis->keys('queue*');
    foreach ($queues as $queue) {
        $redisQueues[$queue] = true;
    }
    ksort($redisQueues);

    foreach ($redisQueues as $queue => $v) {
        addInfo($queue, $redis->lLen($queue));
    }

    addInfo('', 0);

    addInfo('Kills remaining to be fetched.', $mdb->count('crestmails', ['processed' => false]));
    $killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
    addInfo('Kills last hour', $killsLastHour->count());
    addInfo('Total Kills', $mdb->findField('storage', 'contents', ['locker' => 'totalKills']));
    addInfo('Top killID', $mdb->findField('killmails', 'killID', [], ['killID' => -1]));

    addInfo('', 0);
    addInfo('Api KillLogs to check', $redis->zCount('tqApiChars', 0, time()));
    addInfo("Api KeyInfo's to check", $redis->zCount('tqApis', 0, time()));
    addInfo('Char/Corp Apis', $redis->zCard('tqApiChars'));
    addInfo('Valid Apis', $redis->zCard('tqApis'));

    addInfo('', 0);
    $visitors = new RedisTtlCounter('ttlc:visitors', 300);
    addInfo('Unique IPs in last 5 minutes', $visitors->count());
    $requests = new RedisTtlCounter('ttlc:requests', 300);
    addInfo('Requests in last 5 minutes', $requests->count());

    $maxLen = 0;
    foreach ($infoArray as $i) {
        foreach ($i as $key => $value) {
            $maxLen = max($maxLen, strlen("$value"));
        }
    }

    echo exec('clear; date');
    echo "\n";
    echo "\n";
    foreach ($infoArray as $i) {
        foreach ($i as $name => $count) {
            if (trim($name) == '') {
                echo "\n";
                continue;
            }
            while (strlen($count) < (20 + $maxLen)) {
                $count = ' '.$count;
            }
            echo "$count $name\n";
        }
    }
    sleep(3);
}

function addInfo($text, $number)
{
    global $infoArray, $deltaArray;
    $prevNumber = (int) @$deltaArray[$text];
    $delta = $number - $prevNumber;
    $deltaArray[$text] = $number;

    if ($delta > 0) {
        $delta = "+$delta";
    }
    $dtext = $delta == 0 ? '' : "($delta)";
    $infoArray[] = ["$text $dtext" => number_format($number, 0)];
}
