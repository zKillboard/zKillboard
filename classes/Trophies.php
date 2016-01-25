<?php

class Trophies
{
// isk values
// be in tournament region
// freighter burn 

	public static $conditions = [
		['type' => 'General', 'name' => 'First blood: get a kill', 'stats' => ['field' => 'shipsDestroyed', 'value' => 1]],
		['type' => 'General', 'name' => 'Your blood: lose your ship', 'stats' => ['field' => 'shipsLost', 'value' => 1]],
		['type' => 'General', 'name' => 'Popped my cherry: lose a Capsule', 'statGroup' => ['groupID' => 29, 'field' => 'shipsLost', 'value' => 1]],
		['type' => 'General', 'name' => 'Get a solo kill', 'filter' => ['isVictim' => false, 'solo' => true]],
		['type' => 'General', 'name' => 'Just warming up: get 10 kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 10]],
		['type' => 'General', 'name' => 'Need more Ammo: get 100 kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 100]],
		['type' => 'General', 'name' => 'Industry supporter: get 1000 kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 1000]],
		['type' => 'General', 'name' => 'Bitter Vet: get 10k kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 10000]],
		['type' => 'General', 'name' => 'They call me Mr. Mayhem: get 100k kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 100000]],
		['type' => 'General', 'name' => 'Impossible! Inconceivable!: get 1 Million kills', 'stats' => ['field' => 'shipsDestroyed', 'value' => 1000000]],
		['type' => 'Special', 'name' => 'Concordokken! Get concorded', 'filter' => ['isVictim' => false, 'corporationID' => 1000125, 'compare' => true]],
		['type' => 'Special', 'name' => 'What did you do?! Get killed by a CCP dev', 'filter' => ['isVictim' => false, 'corporationID' => 109299958, 'compare' => true]],
		['type' => 'Special', 'name' => 'Banhammer incoming! Kill a CCP dev', 'filter' => ['isVictim' => true, 'corporationID' => 109299958, 'compare' => true]],
		['type' => 'Special', 'name' => 'Get a kill in high sec', 'filter' => ['isVictim' => false, 'highsec' => true]],
		['type' => 'Special', 'name' => 'Get a kill in low sec', 'filter' => ['isVictim' => false, 'lowsec' => true]],
		['type' => 'Special', 'name' => 'Get a kill in null sec', 'filter' => ['isVictim'=> false, 'nullsec' => true]],
		['type' => 'Special', 'name' => 'Get a kill in w-space', 'filter' => ['isVictim' => false, 'wspace' => true]],
		['type' => 'Special', 'name' => 'Participate in a tournament', 'filter' => ['regionID' => 10000004, 'characterID' => '?']],
		['type' => 'Special', 'name' => 'Ganktastic: Kill 50 Freighters', 'statGroup' => ['groupID' => 513, 'field' => 'shipsDestroyed', 'value' => 50]],
		];

	public static function getTrophies($charID)
	{
		global $mdb;

		$charID = (int) $charID;
		$type = 'characterID';

		$stats = $mdb->findDoc("statistics", ['type' => $type, 'id' => $charID]);
		$trophies = $mdb->findDoc("trophies", ['id' => $charID]);
		$trophies = [];
		$maxTrophyCount = 0;
		$trophyCount = 0;
		$rankAvg = null;
		$rankCount = 0;

		foreach (static::$conditions as $condition)
		{
			$maxTrophyCount++;
			if (isset($condition['filter']))
			{
				$filter = $condition['filter'];
				if (isset($filter['characterID'])) $filter['characterID'] = $charID;
				$query = MongoFilter::buildQuery($filter);
				if (isset($filter['compare']))
				{
					$part2 = ['characterID' => $charID, 'isVictim' => !$filter['isVictim']];
					$part2 = MongoFilter::buildQuery($part2);
					$query = ['$and' => [$query, $part2]];
				}
				$exists = $mdb->exists("killmails", $query);
				$trophyCount += static::addTrophy($trophies, $condition, $exists);
			}
			if (isset($condition['stats']))
			{
				$field = $condition['stats']['field'];
				$value = $condition['stats']['value'];
				$trophyCount += static::addTrophy($trophies, $condition, @$stats[$field] >= $value);
			}
			if (isset($condition['statGroup']))
			{
				$group = @$stats['groups'][$condition['statGroup']['groupID']];
				$field = $condition['statGroup']['field'];
				$value = $condition['statGroup']['value'];
				$trophyCount += static::addTrophy($trophies, $condition, @$group[$field] >= $value);
			}
		}

		$groups = $mdb->find("information", ['type' => 'groupID', 'cacheTime' => 3600], ['name' => 1]);

		foreach ($groups as $row)
		{
			if (@$row['categoryID'] != 6) continue;
			$groupID = (int) $row['id'];
			$count = $mdb->count("information", ['groupID' => $groupID]);
			if ($count == 0) continue;

			$kkey = "groupDestroyed:$groupID";
			$lkey = "groupLost:$groupID";

			$groupName = $row['name']; //Info::getInfoField('groupID', $groupID, 'name');
			$a = in_array(substr(strtolower($groupName), 0, 1), ['a', 'e', 'i', 'o', 'u']) ? "an" : "a";
			$values = @$stats['groups'][$groupID];
			$trophies['trophies']['Killed']["Kill $a $groupName"] = @$values['shipsDestroyed'] > 0;
			$trophyCount += @$values['shipsDestroyed'] > 0;
			$trophies['trophies']['Lost']["Lose $a $groupName"] = @$values['shipsLost'] > 0;
			$trophyCount += @$values['shipsLost'] > 0;
			$maxTrophyCount += 2;

			$rank = static::getRank(@$values['shipsDestroyed']);
		}

		$shipClasses = $mdb->getCollection("information")->count(['type' => 'groupID', 'categoryID' => 6]);

		$level = 0;
		$total = 1;
		do {
			$total = $total * 2;
			$level++;
		} while ($total < $trophyCount);
		$completed = number_format(($trophyCount / $maxTrophyCount) * 100, 0);

		$trophies['level'] = $level;
		$trophies['trophyCount'] = $trophyCount;
		$trophies['maxTrophyCount'] = $maxTrophyCount;
		$trophies['nextLevel'] = pow(2, $level);
		$trophies['completedPct'] = $completed;
		$trophies['misc'] = "Level $level / $trophyCount of $maxTrophyCount trophies / $completed% complete.";

		return $trophies;
	}

	public static function addTrophy(&$trophies, $condition, $conditionMet)
	{
		$trophies['trophies'][$condition['type']][$condition['name']] = $conditionMet;
		return $conditionMet ? 1 : 0;
	}

	public static function getRank($value)
	{
		if ($value == 0) return null;
		$rank = 1;
		while (($value / 10) > 1)
		{
			$value = $value / 10;
			$rank++;
		}
		return min(5, $rank);
	}
}
