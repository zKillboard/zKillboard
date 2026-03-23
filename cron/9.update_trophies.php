<?php

require_once "../init.php";

$mdb->set('statistics', ['type' => 'characterID', 'calcTrophies' => true, 'calcTrophies_updated' => ['$exists' => false]], ['calcTrophies_updated' => 0], true);

$minute = date("Hi");
while ($minute == date("Hi")) {
	$twentyFourHoursAgo = time() - 86400;
	$cursor = $mdb->find('statistics', ['type' => 'characterID', 'calcTrophies' => true, 'calcTrophies_updated' => ['$lte' => $twentyFourHoursAgo]], [], 100, ['id' => 1]);
	
	foreach ($cursor as $row) {
		$characterID = (int) @$row['id'];
		if ($characterID <= 0) {
			continue;
		}

		$trophies = Trophies::getTrophies($characterID);
		$trophies['id'] = $characterID;
		$trophies['calcTrophies_updated'] = time();
		$mdb->insertUpdate('trophies', ['id' => $characterID], [
			'trophies' => $trophies,
			'updated' => Mdb::now(),
		]);
        $mdb->set("statistics", ['type' => 'characterID', 'id' => $characterID], ['calcTrophies' => false, 'calcTrophies_updated' => $trophies['calcTrophies_updated']]);
	}
	sleep(1);
}
