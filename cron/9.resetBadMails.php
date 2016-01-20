<?php

require_once '../init.php';

if (date('i') % 5 != 0) exit();

if ($redis->llen("queueProcess") >= 25) exit();

$count = 0;
$crest = $mdb->getCollection('crestmails')->find()->sort(['added' => -1]);
foreach ($crest as $row) {
	$killID = $row['killID'];
	if (isset($row['npcOnly'])) continue;
	$killmail = $mdb->findDoc("killmails", ['killID' => $killID]);
	$count ++;
	if ($killmail != null) {
		if ($count > 10000) exit();
		continue;
	}
	$count = 0;

	$mdb->set('crestmails', ['killID' => $killID], ['processed' => false]);
	sleep(1);
}
