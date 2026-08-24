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

		$lockKey = "zkb:trophies:lock:$characterID";
		if ($redis->set($lockKey, 'true', ['nx', 'ex' => 1800]) !== true) continue;

		try {
			$pending = $mdb->findDoc('statistics', ['type' => 'characterID', 'id' => $characterID, 'calcTrophies' => true, 'calcTrophies_updated' => ['$lte' => $twentyFourHoursAgo]], [], ['id' => 1]);
			if ($pending == null) continue;

			$trophies = Trophies::getTrophies($characterID);
			$trophies['id'] = $characterID;
			$trophies['calcTrophies_updated'] = time();
			$mdb->insertUpdate('trophies', ['id' => $characterID], [
				'trophies' => $trophies,
				'updated' => Mdb::now(),
			]);
			$mdb->set('statistics', ['type' => 'characterID', 'id' => $characterID], ['calcTrophies' => false, 'calcTrophies_updated' => $trophies['calcTrophies_updated']]);
		} finally {
			$redis->del($lockKey);
		}
	}
	sleep(1);
}
