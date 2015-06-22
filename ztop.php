#!/usr/bin/php5
<?php

require_once "init.php";

echo "gathering information....\n";
$redisQueues = [];
$priorKillLog = 0;

$deltaArray = [];

while (true)
{
	$infoArray = [];
	$coll = $mdb->getDb()->listCollections();
	$collections = [];
	foreach($coll as $col) $collections[] = $col->getName();

	sort($collections);
	foreach ($collections as $name)
	{
		if (substr($name, 0, 5) != "queue") continue;
		$count = $mdb->count($name);
		addInfo($name, $count);
	}
	addInfo("", 0);

	$queues = $redis->keys("queue*");
	foreach ($queues as $queue) $redisQueues[$queue] = true;

	foreach ($redisQueues as $queue=>$v) addInfo($queue, $redis->lLen($queue));

	addInfo("", 0);

	addInfo("Kills remaining to be fetched.", $mdb->count("crestmails", ['processed' => false]));
	addInfo("Kills last hour", $mdb->count("oneHour"));
	addInfo("Total Kills", $mdb->findField("storage", 'contents', ['locker' => 'totalKills']));
	addInfo("Top killID", $mdb->findField("killmails", "killID", [], ['killID' => -1]));

	addInfo("", 0);
	addInfo("Api KillLog's to check", $mdb->count("apiCharacters", ['cachedUntil' => [ '$lt' => $mdb->now() ]]));
	addInfo("Api KeyInfo's to check", $mdb->count("apis", ['lastApiUpdate' => [ '$lt' => $mdb->now(-10800) ]]));
	addInfo("Corporation Keys", $mdb->count("apiCharacters", ['type' => 'Corporation']));
	addInfo("Character Keys", $mdb->count("apiCharacters", ['type' => 'Character']));
	addInfo("Account Keys", $mdb->count("apiCharacters", ['type' => 'Account']));
	addInfo("Total KillLog Keys", $mdb->count("apiCharacters"));
	addInfo("Total Apis", $mdb->count("apis"));

	$maxLen = 0;
	foreach($infoArray as $i) foreach ($i as $key=>$value) $maxLen = max($maxLen, strlen("$value"));

	echo exec("clear; date");
	echo "\n";
	echo "\n";
	foreach ($infoArray as $i)
	{
		foreach ($i as $name=>$count)
		{
		if (trim($name) == "") { echo "\n"; continue; }
		while (strlen($count) < (20 + $maxLen)) $count = " " . $count;
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

	if ($delta > 0) $delta = "+$delta";
	$dtext = $delta == 0 ? "" : "($delta)";
	$infoArray[] = ["$text $dtext" => number_format($number, 0)];
}
