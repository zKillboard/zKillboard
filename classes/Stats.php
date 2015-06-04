<?php

class Stats
{

	public static function getTopPilots($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("characterID", $parameters, $allTime);
	}

	public static function getTopPointsPilot($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTopPoints("characterID", $parameters, $allTime);
	}

	public static function getTopCorps($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("corporationID", $parameters, $allTime);
	}

	public static function getTopPointsCorp($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTopPoints("corporationID", $parameters, $allTime);
	}

	public static function getTopAllis($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("allianceID", $parameters, $allTime);
	}

	public static function getTopFactions($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("factionID", $parameters, $allTime);
	}

	public static function getTopPointsAlli($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTopPoints("allianceID", $parameters, $allTime);
	}

	public static function getTopShips($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("shipTypeID", $parameters, $allTime);
	}

	public static function getTopGroups($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("groupID", $parameters, $allTime);
	}

	public static function getTopWeapons($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("weaponTypeID", $parameters, $allTime);
	}

	public static function getTopSystems($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("solarSystemID", $parameters, $allTime);
	}

	public static function getTopRegions($parameters = array(), $allTime = false)
	{
		$parameters["cacheTime"] = 3600;
		return self::getTop("regionID", $parameters, $allTime);
	}

	/**
	 * @param string $groupByColumn
	 */
	public static function getTopPoints($groupByColumn, $parameters = array(), $allTime = false)
	{
		return [];
	}

	public static function getTopIsk($parameters = array(), $allTime = false)
	{
		global $mdb;

		if (!isset($parameters["limit"])) $parameters["limit"] = 5;
		$parameters["orderBy"] = "zkb.totalValue";

		$hashKey = "getTopIsk:" . serialize($parameters);
		$result = Cache::get($hashKey);
		if ($result != null) return $result;

		$result = Kills::getKills($parameters);
		Cache::set($hashKey, $result, 300);

		return $result;
	}

	private static $extendedGroupColumns = array("characterID"); //, "corporationID"); //, "allianceID");

	/**
	 * @param string $groupByColumn
	 */
	public static function getTop($groupByColumn, $parameters = array())
	{
		global $mdb, $debug;

		$hashKey = "Stats::getTop:$groupByColumn:" . serialize($parameters);
		$result = Cache::get($hashKey);
		if ($result != null) return $result;

		if (isset($parameters["pastSeconds"]) && $parameters["pastSeconds"] == 604800) $killmails = $mdb->getCollection("oneWeek");
		else $killmails = $mdb->getCollection("killmails");
		if (isset($parameters["pastSeconds"]) && $parameters["pastSeconds"] == 604800) unset($parameters["pastSeconds"]);

		$query = MongoFilter::buildQuery($parameters);
		if (!$mdb->exists("killmails", $query)) return [];
		$andQuery = MongoFilter::buildQuery($parameters, false);

		if ($groupByColumn == "solarSystemID" || $groupByColumn == "regionID") $keyField = "system.$groupByColumn";
		else $keyField = "involved.$groupByColumn";

		$id = $type = $isVictim = null;
		if ($groupByColumn != "solarSystemID" && $groupByColumn != "regionID") foreach ($parameters as $k=>$v)
		{
			if (strpos($k, "ID") === false) continue;
			if (!is_array($v) || sizeof($v) < 1) continue;
			$id = $v[0];
			if ($k != "solarSystemID" && $k != "regionID") $type = "involved.$k";
			else $type = "system.$k";
		}

		$timer = new Timer();
		$pipeline = [];
		$pipeline[] = [ '$match' =>  $query];
		if ($groupByColumn != "solarSystemID" && $groupByColumn != "regionID") $pipeline[] = ['$unwind' => '$involved'];
		if ($type != null && $id != null) $pipeline[] = ['$match' => [$type => $id, 'involved.isVictim' => false]];
		$pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
		$pipeline[] = ['$match' => $andQuery];
		$pipeline[] = ['$group' => [ '_id' => [ 'killID' => '$killID', $groupByColumn => '$' . $keyField]]];
		$pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => 1] ]];
		$pipeline[] = ['$sort' => ['kills' => -1]];
		if (!isset($parameters["nolimit"])) $pipeline[] = ['$limit' => 10];
		$pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

		if (!$debug) MongoCursor::$timeout = -1;
		$result = $killmails->aggregateCursor($pipeline);
		$result = iterator_to_array($result);

		Info::addInfo($result);
		Cache::set($hashKey, $result, 3600);

		return $result;
	}

	private static function getExtendedTop($groupByColumn, $parameters = array(), $allTime = false)
	{
		return array();
	}

	public static function calcStats($killID, $adding = true)
	{
	}

	/**
	 * @param string $type
	 */
	private static function statLost($type, $typeID, $groupID, $modifier, $points, $isk)
	{
	}

	/**
	 * @param string $type
	 */
	private static function statDestroyed($type, $typeID, $groupID, $modifier, $points, $isk)
	{
	}

	public static function getDistinctCount($groupByColumn, $parameters = [])
	{
		global $mdb, $debug;

		$hashKey = "distinctCount::$groupByColumn:" . serialize($parameters);
		$result = Cache::get($hashKey);
		if ($result != null) return $result;

		if ($parameters == [])
		{
			$type = ($groupByColumn == "solarSystemID" || $groupByColumn == "regionID") ? "system.$groupByColumn" : "involved.$groupByColumn";
			$result = $mdb->getCollection("oneWeek")->distinct($type);
			Cache::set($hashKey, sizeof($result), 3600);
			return sizeof($result);
		}

		$query = MongoFilter::buildQuery($parameters);
		if (!$mdb->exists("oneWeek", $query)) return [];
		$andQuery = MongoFilter::buildQuery($parameters, false);

		if ($groupByColumn == "solarSystemID" || $groupByColumn == "regionID") $keyField = "system.$groupByColumn";
		else $keyField = "involved.$groupByColumn";

		$id = $type = $isVictim = null;
		if ($groupByColumn == "solarSystemID" || $groupByColumn == "regionID") $type = "system.$groupByColumn";
		if ($type == null) $type = "involved.$groupByColumn";

		$timer = new Timer();
		$pipeline = [];
		$pipeline[] = [ '$match' =>  $query];
		if ($groupByColumn != "solarSystemID" && $groupByColumn != "regionID") $pipeline[] = ['$unwind' => '$involved'];
		if ($type != null && $id != null) $pipeline[] = ['$match' => [$type => $id]];
		$pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
		$pipeline[] = ['$match' => $andQuery];
		$pipeline[] = ['$group' => ['_id' => '$' . $type, 'foo' => ['$sum' => 1]]];
		$pipeline[] = ['$group' => ['_id' => 'total', 'value' => ['$sum' => 1]]];

		if (!$debug) MongoCursor::$timeout = -1;
		$result = $mdb->getCollection("oneWeek")->aggregateCursor($pipeline);
		$result = iterator_to_array($result);

		$retValue = sizeof($result) == 0 ? 0 : $result[0]['value'];

		Cache::set($hashKey, $retValue, 3600);
		return $retValue;
	}
}
