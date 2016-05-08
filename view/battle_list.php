<?php

global $mdb, $redis, $battleSize;

$battleSize = (@$battleSize == 0 ? 150 : $battleSize);
$rb = $redis->sMembers("battlesAnnounced");
arsort($rb);

$recentBattles = [];
foreach ($rb as $b) {
	$ex = explode(":", $b);
	$system = Info::getInfoField('solarSystemID', (int) $ex[2], 'name');
	$kills = $redis->get("battle:$b");
	$time = str_replace("*", ":", $ex[1]);
	$timeLink = str_replace(" ", "", $time);
	$timeLink = str_replace("-", "", $timeLink);
	$timeLink = str_replace(":", "", $timeLink);
	
	$recentBattles[] = ['kills' => $kills, 'system' => $system, 'time' => $time, 'link' => "/related/" . $ex[2] . "/" . $timeLink . "/"];
}

$battles = $mdb->find("battles", [], ['battleID' => -1], 50);
Info::addInfo($battles);

$app->render('battles.html', ['battles' => $battles, 'recent' => $recentBattles, 'battleSize' => $battleSize]);
