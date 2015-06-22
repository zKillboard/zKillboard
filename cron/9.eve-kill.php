<?php

require_once "../init.php";

// Send the mails to eve-kill cuz we're nice like that

$timer = new Timer();

$queueShare = new RedisQueue("queueShare");
do
{
	$killID = $queueShare->pop();
	if ($killID >= 45000000) // This is temporary while we're revalidating all older mails
	{
		$hash = $mdb->findField("crestmails", "hash", ['killID' => $killID, 'processed' => true]);
		@file_get_contents("https://beta.eve-kill.net/crestmail/$killID/$hash/");
	}
} while ($killID != null);
