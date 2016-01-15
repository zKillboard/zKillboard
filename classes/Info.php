<?php

class Info
{
	/**
	 * @var array  Used for static caching of getInfoField results
	 */
	static $infoFieldCache;

	public static function getInfoField($type, $id, $field)
	{
		global $mdb, $redis;
		$key = "$type . $id . $field";
		if (isset(self::$infoFieldCache[$key])) {
			return self::$infoFieldCache[$key];
		}

		$data = $redis->hGet("tq:$type:$id", $field);
		if ($data == null) {
			$data = $mdb->findField('information', "$field", ['type' => $type, 'id' => (int) $id, 'cacheTime' => 300]);
		}
		self::$infoFieldCache[$key] = $data;
		return $data;
	}

	public static function getInfo($type, $id)
	{
		global $mdb, $redis;

		$data = $redis->hGetAll("tq:$type:$id");
		if ($data == null) {
			$data = $mdb->findDoc('information', ['type' => $type, 'id' => (int) $id, 'cacheTime' => 300]);
		}

		$data[$type] = (int) $id;
		$data[str_replace('ID', 'Name', $type)] = isset($data['name']) ? $data['name'] : "$type $id";
		switch ($type) {
			case 'solarSystemID':
				$data['security'] = @$data['secStatus'];
				$data['sunTypeID'] = 3802;
				break;
			case 'characterID':
				$data['isCEO'] = $mdb->exists('information', ['type' => 'corporationID', 'id' => (int) @$data['corporationID'], 'ceoID' => (int) $id]);
				$data['isExecutorCEO'] = $mdb->exists('information', ['type' => 'allianceID', 'id' => (int) @$data['allianceID'], 'executorCorpID' => (int) (int) @$data['corporationID']]);
				break;
			case 'corporationID':
				$data['cticker'] = @$data['ticker'];
				break;
			case 'shipTypeID':
				$data['shipTypeName'] = self::getInfoField('typeID', $id, 'name');
				break;
			case 'allianceID':
				$data['aticker'] = @$data['ticker'];
				break;
		}

		return $data;
	}

	public static function getInfoDetails($type, $id)
	{
		global $mdb;

		$data = self::getInfo($type, $id);
		self::addInfo($data);

		$stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => (int) $id]);
		if ($stats == null) {
			$stats = [];
		}
		$data['stats'] = $stats;
		$data[''] = $stats;

		$arr = ['ships', 'isk', 'points'];
		if ($arr != null) {
			foreach ($arr as $a) {
				$data["{$a}Destroyed"] = (int) @$stats["{$a}Destroyed"];
				$data["{$a}DestroyedRank"] = (int) @$stats["{$a}DestroyedRank"];
				$data["{$a}Lost"] = (int) @$stats["{$a}Lost"];
				$data["{$a}LostRank"] = (int) @$stats["{$a}LostRank"];
			}
		}
		$data['overallRank'] = @$stats['overallRank'];

		return $data;
	}

	/**
	 * Fetches information for a wormhole system.
	 *
	 * @param int $systemID
	 *
	 * @return array
	 */
	public static function getWormholeSystemInfo($systemID)
	{
		global $redis;

		if ($systemID < 3100000) {
			return;
		}

		return $redis->hGetAll("tqMap:wh:$systemID");
	}

	/**
	 * @static
	 *
	 * @param int $systemID
	 *
	 * @return float The system secruity of a solarSystemID
	 */
	public static function getSystemSecurity($systemID)
	{
		$systemInfo = self::getInfo('solarSystemID', $systemID);

		return $systemInfo['security'];
	}

	/**
	 * @param int $allianceID
	 *
	 * @return array
	 */
	public static function getCorps($allianceID)
	{
		global $mdb;

		$corpList = $mdb->find('information', ['type' => 'corporationID', 'memberCount' => ['$gt' => 0], 'allianceID' => (int) $allianceID], ['name' => 1]);

		$retList = array();
		foreach ($corpList as $corp) {
			$corp['corporationName'] = $corp['name'];
			$corp['corporationID'] = $corp['id'];
			$apiVerifiedSet = new RedisTtlSortedSet('ttlss:apiVerified', 86400);
			$count = $apiVerifiedSet->getTime((int) $corp['corporationID']);

			if ($count) {
				$corp['cachedUntilTime'] = date('Y-m-d H:i', $count);
				$corp['apiVerified'] = 1;
			}
			self::addInfo($corp);
			$retList[] = $corp;
		}

		return $retList;
	}

	/**
	 * Gets corporation stats.
	 *
	 * @param int   $allianceID
	 * @param array $parameters
	 *
	 * @return array
	 */
	public static function getCorpStats($allianceID, $parameters)
	{
		global $mdb;

		$corpList = $mdb->find('information', ['type' => 'corporationID', 'memberCount' => ['$gt' => 0], 'allianceID' => (int) $allianceID], ['name' => 1]);
		$statList = array();
		foreach ($corpList as $corp) {
			$parameters['corporationID'] = $corp['id'];
			$data = self::getInfoDetails('corporationID', $corp['id']);
			$statList[$corp['name']]['corporationName'] = $data['corporationName'];
			$statList[$corp['name']]['corporationID'] = $data['corporationID'];
			$statList[$corp['name']]['ticker'] = $data['cticker'];
			$statList[$corp['name']]['members'] = (int) @$data['memberCount'];
			$statList[$corp['name']]['ceoName'] = (string) @$data['ceoName'];
			$statList[$corp['name']]['ceoID'] = (int) @$data['ceoID'];
			$statList[$corp['name']]['kills'] = $data['shipsDestroyed'];
			$statList[$corp['name']]['killsIsk'] = $data['iskDestroyed'];
			$statList[$corp['name']]['killPoints'] = $data['pointsDestroyed'];
			$statList[$corp['name']]['losses'] = $data['shipsLost'];
			$statList[$corp['name']]['lossesIsk'] = $data['iskLost'];
			$statList[$corp['name']]['lossesPoints'] = $data['pointsLost'];
			if ($data['iskDestroyed'] != 0 || $data['iskLost'] != 0) {
				$statList[$corp['name']]['effeciency'] = $data['iskDestroyed'] / ($data['iskDestroyed'] + $data['iskLost']) * 100;
			} else {
				$statList[$corp['name']]['effeciency'] = 0;
			}
		}

		return $statList;
	}

	/**
	 * [getFactionTicker description].
	 *
	 * @param string $ticker
	 *
	 * @return string|null
	 */
	public static function getFactionTicker($ticker)
	{
		$data = array(
				'caldari' => array('factionID' => '500001', 'name' => 'Caldari State'),
				'minmatar' => array('factionID' => '500002', 'name' => 'Minmatar Republic'),
				'amarr' => array('factionID' => '500003', 'name' => 'Amarr Empire'),
				'gallente' => array('factionID' => '500004', 'name' => 'Gallente Federation'),
			     );

		if (isset($data[$ticker])) {
			return $data[$ticker];
		}

		return;
	}

	/**
	 * [getRegionInfoFromSystemID description].
	 *
	 * @param int $systemID
	 *
	 * @return array
	 */
	public static function getRegionInfoFromSystemID($systemID)
	{
		global $mdb;

		$regionID = (int) $mdb->findField('information', 'regionID', ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => (int) $systemID]);

		$data = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $regionID]);
		$data['regionID'] = $regionID;
		$data['regionName'] = $data['name'];

		return $data;
	}

	public static function getCorporationTicker($id)
	{
		global $mdb;

		return $mdb->findField('information', 'ticker', ['cacheTime' => 3600, 'type' => 'corporationID', 'id' => (int) $id]);
	}

	public static function getAllianceTicker($allianceID)
	{
		global $mdb;

		$ticker = $mdb->findField('information', 'ticker', ['cacheTime' => 3600, 'type' => 'allianceID', 'id' => (int) $allianceID]);
		if ($ticker != null) {
			return $ticker;
		}

		return '';
	}

	/**
	 * Character affiliation.
	 */
	public static function getCharacterAffiliations($characterID)
	{
		$pheal = Util::getPheal();
		$pheal->scope = 'eve';

		$affiliations = $pheal->CharacterAffiliation(array('ids' => $characterID));

		$corporationID = $affiliations->characters[0]->corporationID;
		$corporationName = $affiliations->characters[0]->corporationName;
		$allianceID = $affiliations->characters[0]->allianceID;
		$allianceName = $affiliations->characters[0]->allianceName;

		// Get the ticker for corp and alliance
		$corporationTicker = self::getCorporationTicker($corporationID);
		$allianceTicker = self::getAllianceTicker($allianceID);

		return array('corporationID' => $corporationID, 'corporationName' => $corporationName, 'corporationTicker' => $corporationTicker, 'allianceID' => $allianceID, 'allianceName' => $allianceName, 'allianceTicker' => $allianceTicker);
	}

	/**
	 * [getGroupID description].
	 *
	 * @param int $id
	 *
	 * @return int
	 */
	public static function getGroupID($id)
	{
		return Info::getInfoField('typeID', $id, 'groupID');
	}

	/**
	 * [addInfo description].
	 *
	 * @param mixed $element
	 *
	 * @return array|null
	 */
	public static function addInfo(&$element)
	{
		global $mdb;

		if ($element == null) {
			return;
		}
		foreach ($element as $key => $value) {
			$class = is_object($value) ? get_class($value) : null;
			if ($class == 'MongoId' || $class == 'MongoDate') {
				continue;
			}
			if (is_array($value)) {
				$element[$key] = self::addInfo($value);
			} elseif ($value != 0) {
				switch ($key) {
					case 'lastChecked':
						$element['lastCheckedTime'] = $value;
						break;
					case 'cachedUntil':
						$element['cachedUntilTime'] = $value;
						break;
					case 'dttm':
						$dttm = is_integer($value) ? $value : strtotime($value);
						$element['ISO8601'] = date('c', $dttm);
						$element['killTime'] = date('Y-m-d H:i', $dttm);
						$element['MonthDayYear'] = date('F j, Y', $dttm);
						break;
					case 'shipTypeID':
						if (!isset($element['shipName'])) {
							$element['shipName'] = self::getInfoField('typeID', $value, 'name');
						}
						if (!isset($element['groupID'])) {
							$element['groupID'] = self::getGroupID($value);
						}
						if (!isset($element['groupName'])) {
							$element['groupName'] = self::getInfoField('groupID', $element['groupID'], 'name');
						}
						break;
					case 'groupID':
						global $loadGroupShips; // ugh
						if (!isset($element['groupName'])) {
							$element['groupName'] = self::getInfoField('groupID', $value, 'name');
						}
						if ($loadGroupShips && !isset($element['groupShips']) && !isset($element['noRecursion'])) {
							$groupTypes = $mdb->find('information', ['cacheTime' => 3600, 'type' => 'typeID', 'groupID' => (int) $value], ['name' => 1]);
							$element['groupShips'] = [];
							foreach ($groupTypes as $type) {
								$type['noRecursion'] = true;
								$type['shipName'] = $type['name'];
								$type['shipTypeID'] = $type['id'];
								$element['groupShips'][] = $type;
							}
						}
						break;
					case 'executorCorpID':
						$element['executorCorpName'] = self::getInfoField('corporationID', $value, 'name');
						break;
					case 'ceoID':
						$element['ceoName'] = self::getInfoField('characterID', $value, 'name');
						break;
					case 'characterID':
						$element['characterName'] = self::getInfoField('characterID', $value, 'name');
						break;
					case 'corporationID':
						$element['corporationName'] = self::getInfoField('corporationID', $value, 'name');
						break;
					case 'allianceID':
						$element['allianceName'] = self::getInfoField('allianceID', $value, 'name');
						break;
					case 'factionID':
						$element['factionName'] = self::getInfoField('factionID', $value, 'name');
						break;
					case 'weaponTypeID':
						$element['weaponTypeName'] = self::getInfoField('typeID', $value, 'name');
						break;
					case 'locationID':
					case 'itemID':
						$element['itemName'] = self::getInfoField('itemID', $value, 'name');
						$element['locationName'] = $element['itemName'];
						$element['typeID'] = self::getInfoField('itemID', $value, 'typeID');
						break;
					case 'typeID':
						if (!isset($element['typeName'])) {
							$element['typeName'] = self::getInfoField('typeID', $value, 'name');
						}
						$groupID = self::getGroupID($value);
						if (!isset($element['groupID'])) {
							$element['groupID'] = $groupID;
						}
						if (!isset($element['groupName'])) {
							$element['groupName'] = self::getInfoField('groupID', $groupID, 'name');
						}
						$element['fittable'] = (int) $mdb->findField('information', 'fittable', ['cacheTime' => 3600, 'type' => 'typeID', 'id' => (int) $value]);
						break;
					case 'solarSystemID':
						$info = self::getInfo('solarSystemID', $value);
						if (sizeof($info)) {
							$element['solarSystemName'] = $info['solarSystemName'];
							$element['sunTypeID'] = $info['sunTypeID'];
							$securityLevel = number_format($info['security'], 1);
							if ($securityLevel == 0 && $info['security'] > 0) {
								$securityLevel = 0.1;
							}
							$element['solarSystemSecurity'] = $securityLevel;
							$element['systemColorCode'] = self::getSystemColorCode($securityLevel);
							$regionInfo = self::getRegionInfoFromSystemID($value);
							$element['regionID'] = $regionInfo['regionID'];
							$element['regionName'] = $regionInfo['regionName'];
							$wspaceInfo = self::getWormholeSystemInfo($value);
							if ($wspaceInfo) {
								$element['systemClass'] = $wspaceInfo['class'];
								$element['systemEffect'] = isset($wspaceInfo['effectName']) ? $wspaceInfo['effectName'] : null;
							}
						}
						break;
					case 'regionID':
						$element['regionName'] = self::getInfoField('regionID', $value, 'name');
						break;
					case 'flag':
						$element['flagName'] = self::getFlagName($value);
						break;
				}
			}
		}

		return $element;
	}

	/**
	 * [getSystemColorCode description].
	 *
	 * @param int $securityLevel
	 *
	 * @return string
	 */
	public static function getSystemColorCode($securityLevel)
	{
		$sec = number_format($securityLevel, 1);
		switch ($sec) {
			case 1.0:
				return '#33F9F9';
			case 0.9:
				return '#4BF3C3';
			case 0.8:
				return '#02F34B';
			case 0.7:
				return '#00FF00';
			case 0.6:
				return '#96F933';
			case 0.5:
				return '#F5F501';
			case 0.4:
				return '#E58000';
			case 0.3:
				return '#F66301';
			case 0.2:
				return '#EB4903';
			case 0.1:
				return '#DC3201';
			default:
				return '#F30202';
		}
	}

	public static $effectFitToSlot = array(
			'12' => 'High Slots',
			'13' => 'Mid Slots',
			'11' => 'Low Slots',
			'2663' => 'Rigs',
			'3772' => 'SubSystems',
			'87' => 'Drone Bay',);

	/**
	 * [$effectToSlot description].
	 *
	 * @var array
	 */
	public static $effectToSlot = array(
			'12' => 'High Slots',
			'13' => 'Mid Slots',
			'11' => 'Low Slots',
			'2663' => 'Rigs',
			'3772' => 'SubSystems',
			'87' => 'Drone Bay',
			'5' => 'Cargo',
			'4' => 'Corporate Hangar',
			'0' => 'Corporate  Hangar', // Yes, two spaces, flag 0 is wierd and should be 4
			'89' => 'Implants',
			'133' => 'Fuel Bay',
			'134' => 'Ore Hold',
			'136' => 'Mineral Hold',
			'137' => 'Salvage Hold',
			'138' => 'Specialized Ship Hold',
			'90' => 'Ship Hangar',
			'148' => 'Command Center Hold',
			'149' => 'Planetary Commodities Hold',
			'151' => 'Material Bay',
			'154' => 'Quafe Bay',
			'155' => 'Fleet Hangar',
			);

	/**
	 * [$infernoFlags description].
	 *
	 * @var array
	 */
	private static $infernoFlags = array(
			4 => array(116, 121),
			12 => array(27, 34), // Highs
			13 => array(19, 26), // Mids
			11 => array(11, 18), // Lows
			2663 => array(92, 98), // Rigs
			3772 => array(125, 132), // Subs
			);

	/**
	 * [getFlagName description].
	 *
	 * @param string $flag
	 *
	 * @return string
	 */
	public static function getFlagName($flag)
	{
		// Assuming Inferno Flags
		$flagGroup = 0;
		foreach (self::$infernoFlags as $infernoFlagGroup => $array) {
			$low = $array[0];
			$high = $array[1];
			if ($flag >= $low && $flag <= $high) {
				$flagGroup = $infernoFlagGroup;
			}
			if ($flagGroup != 0) {
				return self::$effectToSlot["$flagGroup"];
			}
		}
		if ($flagGroup == 0 && array_key_exists($flag, self::$effectToSlot)) {
			return self::$effectToSlot["$flag"];
		}
		if ($flagGroup == 0 && $flag == 0) {
			return 'Corporate  Hangar';
		}
		if ($flagGroup == 0) {
			return;
		}

		return self::$effectToSlot["$flagGroup"];
	}

	/**
	 * [getSlotCounts description].
	 *
	 * @param int $shipTypeID
	 *
	 * @return array
	 */
	public static function getSlotCounts($shipTypeID)
	{
		global $mdb;

		$slotArray = $mdb->findDoc("information", ['type' => 'typeID', 'id' => (int) $shipTypeID, 'cacheTime' => 300], [], ['lowSlotCount', 'midSlotCount', 'highSlotCount', 'rigSlotCount']);

		return $slotArray;
	}

	/**
	 * @param string $title
	 * @param string $field
	 * @param array  $array
	 */
	public static function doMakeCommon($title, $field, $array)
	{
		$retArray = array();
		$retArray['type'] = str_replace('ID', '', $field);
		$retArray['title'] = $title;
		$retArray['values'] = array();
		foreach ($array as $row) {
			$data = $row;
			$data['id'] = $row[$field];
			$data['typeID'] = @$row['typeID'];
			if (isset($row[$retArray['type'].'Name'])) {
				$data['name'] = $row[$retArray['type'].'Name'];
			} elseif (isset($row['shipName'])) {
				$data['name'] = $row['shipName'];
			}
			$data['kills'] = $row['kills'];
			$retArray['values'][] = $data;
		}

		return $retArray;
	}

	public static function getLocationID($solarSystemID, $position) {
		global $redis, $mdb;

		$x = $position['x'];
		$y = $position['y'];
		$z = $position['z'];

		$key = "tqMap:$solarSystemID";
		if (!$redis->exists($key)) {
			$multi = $redis->multi();
			$raw = file_get_contents("https://www.fuzzwork.co.uk/api/mapdata.php?solarsystemid=$solarSystemID&format=json");
			$json = json_decode($raw, true);
			foreach ($json as $row) {
				unset($row['complete']);
				$itemID = $row['itemid'];
				$multi->hSet($key, $itemID, 1);
				foreach ($row as $k=>$v) if ($v !== null) $redis->hSet("tqItemID:$itemID", $k, $v);
			}
			$multi->exec();
		}
		$distances = [];
		$itemIDs = $redis->hGetAll($key);
		foreach ($itemIDs as $itemID=>$v) {
			$row = $redis->hGetAll("tqItemID:$itemID");

			$distance = sqrt(pow($row['x'] - $x, 2) + pow($row['y'] - $y, 2) + pow($row['z'] - $z, 2));
			$distances[$itemID] = $distance;
		}
		asort($distances);
		reset($distances);
		$itemID = key($distances);

		return $itemID;
	}
}
