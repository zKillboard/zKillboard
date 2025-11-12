<?php

$master = true;
$pid = pcntl_fork();
$master = ($pid != 0);

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

if ($redis->get("tobefetched") < 1000) $redis->del("zkb:statsStop");
if ($redis->get("zkb:statsStop") == "true") exit();

if ($redis->get("zkb:reinforced") == true) exit();
$queueStats = new RedisQueue('queueStats');
$minute = date('Hi');

global $mdb, $redis;

// Look for resets in statistics and add them to the queue
$hasResets = false;
$cursor = $mdb->getCollection("statistics")->find();
foreach ($cursor as $row) {
	
    if (@$row['reset'] != true) continue;
	if ($row['type'] != 'characterID') continue;
	$raw = $row['type'] . ":" . $row['id'];
	$redis->sadd("queueStatsSet", $raw);
	$hasResets = true;
}
