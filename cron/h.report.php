<?php

require_once "../init.php";

$minute = (int) date("i");
if ($minute != 0) exit();

$mdb = new Mdb();

$killsLastHour = new RedisTtlCounter("killsLastHour", 3600);
$kills = $killsLastHour->count();
$count = $mdb->findField("storage", "contents", ['locker' => 'totalKills']);

if ($kills > 0)
{
	Log::irc("|g|" . number_format($kills, 0) . "|n| kills processed.");
	Util::out(number_format($kills, 0) . " kills added, now at " . number_format($count, 0) . " kills.");
}
