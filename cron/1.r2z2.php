<?php

require_once "../init.php";

if (!(@$pollR2Z2 ?? false)) {
	exit();
}

$r2z2 = "https://r2z2.zkillboard.com";

$options = array(
    'http' => array(
        'method' => 'GET',
        'user_agent' => "zkill r2z2 fetcher"
    )
);
$context = stream_context_create($options);

$latestSequenceFetched = $kvc->get("r2z2LatestSequenceFetched");
if (!$latestSequenceFetched) {
	$raw = @file_get_contents("$r2z2/ephemeral/sequence.json", false, $context);
	$json = json_decode($raw, true);
	if (isset($json['sequence'])) {
		$latestSequenceFetched = $json['sequence'];
		$latestSequenceFetched--; // we increment on the first loop, so start one back
	} else {
		Util::out("Failed to fetch initial sequence from r2z2");
		exit();	
	}
}

$errors = (int) $kvc->get("r2z2ErrorCount");

$minute = date("Hi");
while (date("Hi") == $minute) {
	$latestSequenceFetched++;
	$now = time();
	$raw = @file_get_contents("$r2z2/ephemeral/$latestSequenceFetched.json", false, $context);
	$json = json_decode($raw, true);
	if (isset($json['killmail_id'])) {
		$killID = $json['killmail_id'];
		$hash = $json['hash'];
		if ($killID > 0 && $hash != "") {
			Killmail::addMail($killID, $hash, 'r2z2-fetcher.php', 0);
			$kvc->set("r2z2LatestSequenceFetched", $latestSequenceFetched);
		}
		Util::out("Fetched killID $killID with sequence $latestSequenceFetched from r2z2");
		
		$errors = 0;
		$sleep = max(1, time() - $now) * 1000000;
		if ($sleep < 250000) {
			usleep($sleep); // 4 per second which will stay well under the 20/s rate limit
		}
	} else {
		$latestSequenceFetched--;
		sleep(6);
		$errors++;
		if ($errors > 25) {
			Util::out("Too many errors fetching from r2z2, exiting...");
			$kvc->del("r2z2LatestSequenceFetched");
			exit();
		}
	}
	$kvc->set("r2z2ErrorCount", $errors);
}