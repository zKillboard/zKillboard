<?php

require_once "../init.php";

/*MongoCursor::$timeout = -1;
$systems = $mdb->find("information", ['type' => 'solarSystemID']);
foreach ($systems as $system) {
	$systemID = (int) $system['id'];
	if ($systemID == 0) continue;
	$constID = (int) $system['constellationID'];
	echo "$systemID " . $system['name'] . " ";
	$r = $mdb->set("killmails", ['$and' => [['system.solarSystemID' => $systemID], ['system.constellationID' => null]]], ['system.constellationID' => $constID], true);
	$count = $r['n'];
	echo " $count\n";
	if ($count) sleep(10);
}
exit();*/

do {
	$mail = $mdb->findDoc("killmails", ["system.constellationID" => null]);
	if ($mail != null) {
		$killID = $mail["killID"];
		$systemID = $mail["system"]["solarSystemID"];
		$const = $mdb->findDoc("information", ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => (int) $systemID]);
		$constID = (int) $const["constellationID"];
		$mdb->set("killmails", ['killID' => $killID], ['system.constellationID' => $constID]);
	}
	//usleep(100);
} while ($mail != null);
