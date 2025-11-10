<?php

require_once "../init.php";

$crest = $mdb->getCollection("crestmails")->find()->sort(['killID' => -1]);
while ($crest->hasNext()) {
	$mail = $crest->next();
	$killID = $mail['killID'];
	$kill = $mdb->findDoc("killmails", ['killID' => $killID]);
	if ($kill === null) {
		$mdb->set("crestmails", ['killID' => $killID], ['processed' => false]);
		echo "missing $killID\n";
	}

}
