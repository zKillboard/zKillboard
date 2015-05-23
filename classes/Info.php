<?php

class Info
{
	/**
	 * @static
	 * @param int $systemID
	 * @return array Returns an array containing the solarSystemName and security of a solarSystemID
	 */
	public static function getSystemInfo($systemID)
	{
		global $mdb;

		$data = $mdb->findDoc("information", ['cacheTime' => 1500, 'type' => 'solarSystemID', 'id' => (int) $systemID]);
			$data["solarSystemID"] = $data["id"];
			$data["solarSystemName"] = $data["name"];
			$data["security"] = $data["secStatus"];
			$data["sunTypeID"] = 3802;
			return $data;
	}

	/**
	 * Fetches information for a wormhole system
	 * @param  int $systemID
	 * @return array
	 */
	public static function getWormholeSystemInfo($systemID)
	{
		if ($systemID < 3100000) return;
		return Db::queryRow("select * from ccp_zwormhole_info where solarSystemID = :systemID",
				array(":systemID" => $systemID), 3600);
	}

	/**
	 * @static
	 * @param int $systemID
	 * @return string The system name of a solarSystemID
	 */
	public static function getSystemName($systemID)
	{
		$systemInfo = self::getSystemInfo($systemID);
		return $systemInfo['solarSystemName'];
	}

	/**
	 * @static
	 * @param int $systemID
	 * @return double The system secruity of a solarSystemID
	 */
	public static function getSystemSecurity($systemID)
	{
		$systemInfo = self::getSystemInfo($systemID);
		return $systemInfo['security'];
	}

	/**
	 * @static
	 * @param int $typeID
	 * @return string The item name.
	 */
	public static function getItemName($typeID)
	{
		global $mdb;
		$name = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'typeID', 'id' => (int) $typeID]);
		return $name;
	}

	/**
	 * Retrieves the name of a corporation ID
	 *
	 * @param string $name
	 * @return int The corporationID of a corporation
	 */
	public static function getCorpID($name)
	{
		global $mdb;

		$id = (int) $mdb->findField("information", "id", ['cacheTime' => 3600, 'type' => 'corporationID', 'name' => "/$name/i"]);
		if ($id > 0) return $id;
		return 0;
	}

	/**
	 * @param int $allianceID
	 * @return array
	 */
	public static function getCorps($allianceID)
	{
		global $mdb;

		$corpList = $mdb->find("information", ['type' => 'corporationID', 'memberCount' => ['$gt' => 0], 'allianceID' => (int) $allianceID], ['name' => 1]);

		$retList = array();
		foreach ($corpList as $corp) {
			$corp["corporationName"] = $corp["name"];
			$corp["corporationID"] = $corp["id"];
			$count = $mdb->count("apiCharacters", ['corporationID' => (int) $corp["id"], 'type' => 'Corporation']);
			$corp["apiVerified"] = $count > 0 ? 1 : 0;
			

			if ($count) {
				$corp["keyCount"] = $mdb->count("apiCharacters", ['corporationID' => (int) $corp["id"], 'type' => 'Corporation']);
				$errorValues = array();
				$nextCheck = $mdb->findField("apiCharacters", "cachedUntil", ['type' => 'Corporation', 'corporationID' => (int) $corp["id"]], ['cachedUntil' => -1]);
				$corp["cachedUntilTime"] = date("Y-m-d H:i", $nextCheck->sec);
			}
			else {
				$count = $mdb->count("apiCharacters", ['corporationID' => (int) $corp["id"]]);
				$percentage = $corp["memberCount"] == 0 ? 0 : $count / $corp["memberCount"];
				if ($percentage == 1) $corp["apiVerified"] = 1;
				else if ($percentage > 0) $corp["apiPercentage"] = number_format($percentage * 100, 1);
			}
			self::addInfo($corp);
			$retList[] = $corp;
		}
		return $retList;
	}

	/**
	 * Gets corporation stats
	 * @param  int $allianceID
	 * @param  array $parameters
	 * @return array
	 */
	public static function getCorpStats($allianceID, $parameters)
	{
		global $mdb;

		$corpList = $mdb->find("information", ['type' => 'corporationID', 'memberCount' => ['$gt' => 0], 'allianceID' => (int) $allianceID], ['name' => 1]);
		$statList = array();
		foreach($corpList as $corp)
		{
			$parameters["corporationID"] = $corp["id"];
			$data = self::getCorpDetails($corp["id"], $parameters);
			$statList[$corp["name"]]["corporationName"] = $data["corporationName"];
			$statList[$corp["name"]]["corporationID"] = $data["corporationID"];
			$statList[$corp["name"]]["ticker"] = $data["cticker"];
			$statList[$corp["name"]]["members"] = (int) @$data["memberCount"];
			$statList[$corp["name"]]["ceoName"] = (string) @$data["ceoName"];
			$statList[$corp["name"]]["ceoID"] = (int) @$data["ceoID"];
			$statList[$corp["name"]]["kills"] = $data["shipsDestroyed"];
			$statList[$corp["name"]]["killsIsk"] = $data["iskDestroyed"];
			$statList[$corp["name"]]["killPoints"] = $data["pointsDestroyed"];
			$statList[$corp["name"]]["losses"] = $data["shipsLost"];
			$statList[$corp["name"]]["lossesIsk"] = $data["iskLost"];
			$statList[$corp["name"]]["lossesPoints"] = $data["pointsLost"];
			if($data["iskDestroyed"] != 0 || $data["iskLost"] != 0)
				$statList[$corp["name"]]["effeciency"] = $data["iskDestroyed"] / ($data["iskDestroyed"] + $data["iskLost"]) * 100;
			else $statList[$corp["name"]]["effeciency"] = 0;
		}
		return $statList;
	}

	/**
	 * Gets an alliance name
	 * @param  int $id
	 * @return string
	 */
	public static function getAlliName($id)
	{
		global $mdb;

		$name = $mdb->findField("information", "name", ['type' => 'allianceID', 'id' => (int) $id]);
		if ($name == null) return "Alliance $id";
		return $name;
	}

	/**
	 * [getFactionTicker description]
	 * @param  string $ticker
	 * @return string|null
	 */
	public static function getFactionTicker($ticker)
	{
		$data = array(
				"caldari"	=> array("factionID" => "500001", "name" => "Caldari State"), 
				"minmatar"	=> array("factionID" => "500002", "name" => "Minmatar Republic"), 
				"amarr"		=> array("factionID" => "500003", "name" => "Amarr Empire"), 
				"gallente"	=> array("factionID" => "500004", "name" => "Gallente Federation")
			     );

		if (isset($data[$ticker])) return $data[$ticker];
		return null;
	}

	/**
	 * [getFactionName description]
	 * @param  int $id
	 * @return string|false
	 */
	public static function getFactionName($id)
	{
		global $mdb;
		$name = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'factionID', 'id' => (int) $id]);
		if ($name != null) return $name;
		return isset($data["name"]) ? $data["name"] : "Faction $id";
	}

	/**
	 * [getRegionName description]
	 * @param  int $id
	 * @return string
	 */
	public static function getRegionName($id)
	{
		global $mdb;
		$data = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'regionID', 'id' => (int) $id]);
		return $data;
	}

	/**
	 * [getRegionInfoFromSystemID description]
	 * @param  int $systemID
	 * @return array
	 */
	public static function getRegionInfoFromSystemID($systemID)
	{
		global $mdb;

		$regionID = (int) $mdb->findField("information", "regionID", ['cacheTime' => 3600, 'type' => 'solarSystemID', 'id' => (int) $systemID]);

		$data = $mdb->findDoc("information", ['cacheTime' => 3600, 'type' => 'regionID', 'id' => $regionID]);
		$data["regionID"] = $regionID;
		$data["regionName"] = $data["name"];
		return $data;
	}

	/**
	 * Attempt to find the name of a corporation in the corporations table.	If not found then attempt to pull the name via an API lookup.
	 *
	 * @static
	 * @param int $id
	 * @return string The name of the corp if found, null otherwise.
	 */
	public static function getCorpName($id)
	{
		global $mdb;

		$name = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'corporationID', 'id' => (int) $id]);
		if ($name != null) return $name;
		return "Corporation $id";
	}

	public static function getCorporationTicker($id)
	{
		global $mdb;

		return $mdb->findField("information", "ticker", ['cacheTime' => 3600, 'type' => 'corporationID', 'id' => (int) $id]);
	}

	public static function getAllianceTicker($allianceID)
	{
		global $mdb;

		$ticker = $mdb->findField("information", "ticker", ['cacheTime' => 3600, 'type' => 'allianceID', 'id' => (int) $allianceID]);
		if ($ticker != null) return $ticker;
		return "";
	}

	/**
	 * Attempt to find the name of a character in the characters table.	If not found then attempt to pull the name via an API lookup.
	 *
	 * @static
	 * @param int $id
	 * @return string The name of the corp if found, null otherwise.
	 */
	public static function getCharName($id)
	{
		global $mdb;

		$name = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'characterID', 'id' => (int) $id]);
		if ($name != null) return $name;
		return "Character $id";
	}

	/**
	 * Character affiliation
	 */
	public static function getCharacterAffiliations($characterID)
	{
		$pheal = Util::getPheal();
		$pheal->scope = "eve";

		$affiliations = $pheal->CharacterAffiliation(array("ids" => $characterID));

		$corporationID = $affiliations->characters[0]->corporationID;
		$corporationName = $affiliations->characters[0]->corporationName;
		$allianceID = $affiliations->characters[0]->allianceID;
		$allianceName = $affiliations->characters[0]->allianceName;

		// Get the ticker for corp and alliance
		$corporationTicker = Info::getCorporationTicker($corporationID);
		$allianceTicker = Info::getAllianceTicker($allianceID);

		return array("corporationID" => $corporationID, "corporationName" => $corporationName, "corporationTicker" => $corporationTicker, "allianceID" => $allianceID, "allianceName" => $allianceName, "allianceTicker" => $allianceTicker);

	}

	/**
	 * [getGroupID description]
	 * @param  int $id
	 * @return int
	 */
	public static function getGroupID($id)
	{
		global $mdb;

		$groupID = (int) $mdb->findField("information", "groupID", ['cacheTime' => 3600, 'type' => 'typeID', 'id' => (int) $id]);
		return $groupID;
	}

	/**
	 * Get the name of the group
	 *
	 * @static
	 * @param int $groupID
	 * @return string
	 */
	public static function getGroupName($groupID)
	{
		global $mdb;

		$name = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'groupID', 'id' => (int) $groupID]);
		return $name;
	}

	public static function findEntity($search)
	{
		$result = Db::query("select * from zz_name_search where name like :search limit 10", ['search' => "$search%"], 3600);
		return $result;
	}

	/**
	 * @param string $search
	 */
	private static function findEntitySearch(&$resultArray, $type, $query, $search)
	{
		$results = Db::query("${query}", array(":search" => $search), 3600);
		self::addResults($resultArray, $type, $results);
	}

	/**
	 * [addResults description]
	 * @param array $resultArray
	 * @param string $type
	 * @param array|null $results
	 */
	private static function addResults(&$resultArray, $type, $results)
	{
		if ($results != null) foreach ($results as $result) {
			$keys = array_keys($result);
			$result["type"] = $type;
			$value = $result[$keys[0]];
			$resultArray["$type|$value"] = $result;
		}
	}

	/**
	 * Gets a pilots details
	 * @param  int $id
	 * @return array
	 */
	public static function getPilotDetails($id, $parameters = array())
	{
		global $mdb;

		$data = $mdb->findDoc("information", ['cacheTime' => 1500, 'type' => 'characterID', 'id' => (int) $id]);
		if ($data != null) $data["characterID"] = (int) $id;
		if ($data != null) $data["characteName"] = $data["name"];
		if ($data == null) $data = [];


		self::addInfo($data);
		$data["isCEO"] = $mdb->exists("information", ['type' => 'corporationID', 'id' => (int) @$data["corporationID"], 'ceoID' => (int) $id]);
		$data["isExecutorCEO"] = $mdb->exists("information", ['type' => 'allianceID', 'id' => (int) @$data['allianceID'], 'executorCorpID' => (int) (int) @$data["corporationID"]]);

		$retValue = $parameters == null ? $data : Summary::getPilotSummary($data, $id, $parameters);
		return $retValue;
	}

	/**
	 * [getCorpDetails description]
	 * @param  int $id
	 * @param  array  $parameters
	 * @return array
	 */
	public static function getCorpDetails($id, $parameters = array())
	{
		global $mdb;

		$data = $mdb->findDoc("information", ['cacheTime' => 1500, 'type' => 'corporationID', 'id' => (int) $id]);
		if ($data != null) $data["corporationID"] = (int) $id;
		if ($data != null) $data["corporationName"] = $data["name"];
		if ($data != null) $data["cticker"] = @$data["ticker"];

		self::addInfo($data);
		$retValue = Summary::getCorpSummary($data, $id, $parameters);

		return $retValue;
	}

	/**
	 * [getAlliDetails description]
	 * @param  int $id
	 * @param  array  $parameters
	 * @return array
	 */
	public static function getAlliDetails($id, $parameters = array())
	{
		global $mdb;

		$data = $mdb->findDoc("information", ['cacheTime' => 1500, 'type' => 'allianceID', 'id' => (int) $id]);
		if ($data != null) $data["allianceID"] = (int) $id;
		if ($data != null) $data["allianceName"] = @$data["name"];
		if ($data != null) $data["aticker"] = @$data["ticker"];

		self::addInfo($data);
		$retValue = Summary::getAlliSummary($data, $id, $parameters);

		return $retValue;
	}

	/**
	 * [getFactionDetails description]
	 * @param  int $id
	 * @return array
	 */
	public static function getFactionDetails($id, $parameters = array())
	{
		$data["factionID"] = $id;
		self::addInfo($data);
		return Summary::getFactionSummary($data, $id, $parameters);
	}

	/**
	 * [getSystemDetails description]
	 * @param  int $id
	 * @return array
	 */
	public static function getSystemDetails($id, $parameters = array())
	{
		$data = array("solarSystemID" => $id);
		self::addInfo($data);
		$retValue = Summary::getSystemSummary($data, $id, $parameters);

		return $retValue;
	}

	/**
	 * [getRegionDetails description]
	 * @param  int $id
	 * @return array
	 */
	public static function getRegionDetails($id, $parameters = array())
	{
		$data = array("regionID" => $id);
		self::addInfo($data);
		return Summary::getRegionSummary($data, $id, $parameters);
	}

	/**
	 * [getGroupDetails description]
	 * @param  int $id
	 * @return array
	 */
	public static function getGroupDetails($id)
	{
		$data = array("groupID" => $id);
		self::addInfo($data);
		return Summary::getGroupSummary($data, $id);
	}

	/**
	 * [getShipDetails description]
	 * @param  int $id
	 * @return array
	 */
	public static function getShipDetails($id)
	{
		$data = array("shipTypeID" => $id);
		self::addInfo($data);
		$data["shipTypeName"] = $data["shipName"];
		return Summary::getShipSummary($data, $id);
	}

	/**
	 * [addInfo description]
	 * @param mixed $element
	 * @return array|null
	 */
	public static function addInfo(&$element)
	{
		global $mdb;

		if ($element == null) return;
		foreach ($element as $key => $value) {
			$class = is_object($value) ? get_class($value) : null;
			if ($class == "MongoId" || $class == "MongoDate") continue;
			if (is_array($value)) $element[$key] = self::addInfo($value);
			else if ($value != 0) switch ($key) {
				case "lastChecked":
					$element["lastCheckedTime"] = $value;
					break;
				case "cachedUntil":
					$element["cachedUntilTime"] = $value;
					break;
				case "dttm":
					$dttm = strtotime($value);
					$element["ISO8601"] = date("c", $dttm);
					$element["killTime"] = date("Y-m-d H:i", $dttm);
					$element["MonthDayYear"] = date("F j, Y", $dttm);
					break;
				case "shipTypeID":
					if (!isset($element["shipName"])) $element["shipName"] = self::getItemName($value);
					if (!isset($element["groupID"])) $element["groupID"] = self::getGroupID($value);
					if (!isset($element["groupName"])) $element["groupName"] = self::getGroupName($element["groupID"]);
					break;
				case "groupID":
					global $loadGroupShips; // ugh
					if (!isset($element["groupName"])) $element["groupName"] = self::getGroupName($value);
					if ($loadGroupShips && !isset($element["groupShips"]) && !isset($element["noRecursion"]))
					{
						$groupTypes = $mdb->find("information", ['cacheTime' => 3600, 'type' => 'typeID', 'groupID' => (int) $value], ['name' => 1]);
						$element["groupShips"] = [];
						foreach ($groupTypes as $type)
						{
							$type["noRecursion"] = true;
							$type["shipName"] = $type["name"];
							$type["shipTypeID"] = $type["id"];
							$element["groupShips"][] = $type;
						}
					}
					break;
				case "executorCorpID":
					$element["executorCorpName"] = self::getCorpName($value);
					break;
				case "ceoID":
					$element["ceoName"] = self::getCharName($value);
					break;
				case "characterID":
					$element["characterName"] = self::getCharName($value);
					break;
				case "corporationID":
					$element["corporationName"] = self::getCorpName($value);
					break;
				case "allianceID":
					$element["allianceName"] = self::getAlliName($value);
					break;
				case "factionID":
					$element["factionName"] = self::getFactionName($value);
					break;
				case "weaponTypeID":
					$element["weaponTypeName"] = self::getItemName($value);
					break;
				case "typeID":
					if (!isset($element["typeName"])) $element["typeName"] = self::getItemName($value);
					$groupID = self::getGroupID($value);
					if (!isset($element["groupID"])) $element["groupID"] = $groupID;
					if (!isset($element["groupName"])) $element["groupName"] = self::getGroupName($groupID);
					break;
				case "solarSystemID":
					$info = self::getSystemInfo($value);
					if (sizeof($info)) {
						$element["solarSystemName"] = $info["solarSystemName"];
						$element["sunTypeID"] = $info["sunTypeID"];
						$securityLevel = number_format($info["security"], 1);
						if ($securityLevel == 0 && $info["security"] > 0) $securityLevel = 0.1;
						$element["solarSystemSecurity"] = $securityLevel;
						$element["systemColorCode"] = self::getSystemColorCode($securityLevel);
						$regionInfo = self::getRegionInfoFromSystemID($value);
						$element["regionID"] = $regionInfo["regionID"];
						$element["regionName"] = $regionInfo["regionName"];
						$wspaceInfo = self::getWormholeSystemInfo($value);
						if ($wspaceInfo) {
							$element["systemClass"] = $wspaceInfo["class"];
							$element["systemEffect"] = $wspaceInfo["effectName"];
						}
					}
					break;
				case "regionID":
					$element["regionName"] = self::getRegionName($value);
					break;
				case "flag":
					$element["flagName"] = self::getFlagName($value);
					break;
			}
		}
		return $element;
	}

	/**
	 * [getSystemColorCode description]
	 * @param  int $securityLevel
	 * @return string
	 */
	public static function getSystemColorCode($securityLevel)
	{
		$sec = number_format($securityLevel, 1);
		switch ($sec) {
			case 1.0:
				return "#33F9F9";
			case 0.9:
				return "#4BF3C3";
			case 0.8:
				return "#02F34B";
			case 0.7:
				return "#00FF00";
			case 0.6:
				return "#96F933";
			case 0.5:
				return "#F5F501";
			case 0.4:
				return "#E58000";
			case 0.3:
				return "#F66301";
			case 0.2:
				return "#EB4903";
			case 0.1:
				return "#DC3201";
			default:
			case 0.0:
				return "#F30202";
		}
		return "";
	}


	public static $effectFitToSlot = array(
			"12" => "High Slots",
			"13" => "Mid Slots",
			"11" => "Low Slots",
			"2663" => "Rigs",
			"3772" => "SubSystems",
			"87" => "Drone Bay",);

	/**
	 * [$effectToSlot description]
	 * @var array
	 */
	public static $effectToSlot = array(
			"12" => "High Slots",
			"13" => "Mid Slots",
			"11" => "Low Slots",
			"2663" => "Rigs",
			"3772" => "SubSystems",
			"87" => "Drone Bay",
			"5" => "Cargo",
			"4" => "Corporate Hangar",
			"0" => "Corporate  Hangar", // Yes, two spaces, flag 0 is wierd and should be 4
			"89" => "Implants",
			"133" => "Fuel Bay",
			"134" => "Ore Hold",
			"136" => "Mineral Hold",
			"137" => "Salvage Hold",
			"138" => "Specialized Ship Hold",
			"90" => "Ship Hangar",
			"148" => "Command Center Hold",
			"149" => "Planetary Commodities Hold",
			"151" => "Material Bay",
			"154" => "Quafe Bay",
			"155" => "Fleet Hangar",
			);

	/**
	 * [$infernoFlags description]
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
	 * [getFlagName description]
	 * @param  string $flag
	 * @return string
	 */
	public static function getFlagName($flag)
	{
		// Assuming Inferno Flags
		$flagGroup = 0;
		foreach (self::$infernoFlags as $infernoFlagGroup => $array) {
			$low = $array[0];
			$high = $array[1];
			if ($flag >= $low && $flag <= $high) $flagGroup = $infernoFlagGroup;
			if ($flagGroup != 0) return self::$effectToSlot["$flagGroup"];
		}
		if ($flagGroup == 0 && array_key_exists($flag, self::$effectToSlot)) return self::$effectToSlot["$flag"];
		if ($flagGroup == 0 && $flag == 0) return "Corporate  Hangar";
		if ($flagGroup == 0) return null;
		return self::$effectToSlot["$flagGroup"];
	}

	/**
	 * [getSlotCounts description]
	 * @param  int $shipTypeID
	 * @return array
	 */
	public static function getSlotCounts($shipTypeID)
	{
		$result = Db::query("select attributeID, valueInt, valueFloat from ccp_dgmTypeAttributes where typeID = :typeID and attributeID in (12, 13, 14, 1137)",
				array(":typeID" => $shipTypeID), 86400);
		$slotArray = array();
		foreach ($result as $row) {
			if($row["valueInt"] == NULL && $row["valueFloat"] != NULL)
				$value = $row["valueFloat"];
			elseif($row["valueInt"] != NULL && $row["valueFloat"] == NULL)
				$value = $row["valueInt"];
			else
				$value = NULL;

			if ($row["attributeID"] == 12) $slotArray["lowSlotCount"] = $value;
			else if ($row["attributeID"] == 13) $slotArray["midSlotCount"] = $value;
			else if ($row["attributeID"] == 14) $slotArray["highSlotCount"] = $value;
			else if ($row["attributeID"] == 1137) $slotArray["rigSlotCount"] = $value;
		}
		return $slotArray;
	}

	/**
	 * @param string $title
	 * @param string $field
	 * @param array $array
	 */
	public static function doMakeCommon($title, $field, $array) {
		$retArray = array();
		$retArray["type"] = str_replace("ID", "", $field);
		$retArray["title"] = $title;
		$retArray["values"] = array();
		foreach($array as $row) {
			$data = $row;
			$data["id"] = $row[$field];
			if (isset($row[$retArray["type"] . "Name"])) $data["name"] = $row[$retArray["type"] . "Name"];
			else if(isset($row["shipName"])) $data["name"] = $row["shipName"];
			$data["kills"] = $row["kills"];
			$retArray["values"][] = $data;
		}
		return $retArray;
	}
}
