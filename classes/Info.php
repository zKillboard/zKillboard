<?php

class Info
{
    /**
     * @var array Used for static caching of getInfoField results
     */
    public static $infoFieldCache = [];

    public static function getRedisKey($type, $id)
    {
        return "info::$type:$id";
    }

    public static function loadIntoRedis($type, $id)
    {
        global $mdb, $redis;

        if ($id == null) return;
        $redisKey = self::getRedisKey($type, $id);
        $searchType = ($type == 'shipTypeID' ? 'typeID' : $type);
        $data = $mdb->findDoc('information', ['type' => $searchType, 'id' => (int) $id]);
        if ($data === null) {
            return [];
        }

        unset($data['_id']);

        $data[$type] = (int) $id;
        $data[str_replace('ID', 'Name', $type)] = isset($data['name']) ? $data['name'] : "$type $id";
        switch ($type) {
            case 'solarSystemID':
                $starID = (int) @$data['star_id'];
                $starInfo = Info::getInfo('starID', $starID);
                if ($starInfo != null) $data['sunTypeID'] = $starInfo['type_id'];
                if ($starInfo == null || $starInfo['type_id'] == null) $data['sunTypeID'] = 3802;

                $data['security'] = @$data['secStatus'];
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

        $redis->setex($redisKey, 900, serialize($data));

        return $data;
    }

    public static function getInfoField($type, $id, $field)
    {
        $data = self::getInfo($type, $id);

        return @$data[$field];
    }

    public static function getInfo($type, $id)
    {
        global $redis;

        $redisKey = self::getRedisKey($type, $id);
        $data = @self::$infoFieldCache[$redisKey];
        if ($data == null) {
            try {
                $raw = $redis->get($redisKey);
                if ($raw != null) $data = unserialize($raw);
            } catch (Exception $ex) { 
                // some sort of error when trying to unserialize... 
                $data = null;
            }
            if ($data == null) $data = self::loadIntoRedis($type, $id);
            self::$infoFieldCache[$redisKey] = $data;
        }

        return $data;
    }

    public static function getInfoDetails($type, $id)
    {
        global $mdb;

        if ($type == 'itemID') $type = 'locationID';
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

    public static function getSystemByEpoch($solarSystemID, $epoch) {
        global $mdb;

        $serverVersion = $mdb->findField("versions", "serverVersion", ['epoch' => ['$gte' => $epoch]], ['epoch' => 1]);
        if ($serverVersion == null) throw Exception("Unknown server version - bailing");
        $system = $mdb->findDoc("geography", ['type' => 'solarSystemID', 'id' => $solarSystemID, 'serverVersion' => "$serverVersion"]);
        return $system;
    }

    /**
     * @param int $allianceID
     *
     * @return array
     */
    public static function getCorps($allianceID)
    {
        global $mdb, $redis;

        $corpList = $mdb->find('information', ['type' => 'corporationID', 'allianceID' => (int) $allianceID], ['name' => 1]);

        $retList = array();
        foreach ($corpList as $corp) {
            $corp['corporationName'] = $corp['name'];
            $corp['corporationID'] = $corp['id'];
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

        $data = $mdb->findDoc('information', ['cacheTime' => 3600, 'type' => 'regionID', 'id' => (int) $regionID]);
        try {
            if (!is_array($data)) $data = ['solarSystemID' => $systemID, "name" => "System $systemID"];
            $data['regionID'] = $regionID;
            $data['regionName'] = isset($data['name']) ? $data['name'] : $regionID;
        } catch (Exception $ex) {
            Log::log("Bad data in Info ~244\n" . print_r($data));
            throw $ex;
        }

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
     * [getGroupID description].
     *
     * @param int $id
     *
     * @return int
     */
    public static function getGroupID($id)
    {
        return self::getInfoField('typeID', $id, 'groupID');
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
                        if (!isset($element['executorCorpName'])) {
                            $element['executorCorpName'] = self::getInfoField('corporationID', $value, 'name');
                        }
                        break;
                    case 'ceoID':
                        if (!isset($element['ceoName'])) {
                            $element['ceoName'] = self::getInfoField('characterID', $value, 'name');
                        }
                        break;
                    case 'characterID':
                        if (!isset($element['characterName'])) {
                            $element['characterName'] = self::getInfoField('characterID', $value, 'name');
                        }
                        break;
                    case 'corporationID':
                        if (!isset($element['corporationName'])) {
                            $element['corporationName'] = self::getInfoField('corporationID', $value, 'name');
                        }
                        if (!isset($element['cticker'])) {
                            $element['cticker'] = self::getInfoField('corporationID', $value, 'ticker');
                        }
                        break;
                    case 'allianceID':
                        if (!isset($element['allianceName'])) {
                            $element['allianceName'] = self::getInfoField('allianceID', $value, 'name');
                        }
                        if (!isset($element['aticker'])) {
                            $element['aticker'] = self::getInfoField('allianceID', $value, 'ticker');
                        }
                        break;
                    case 'factionID':
                        if (!isset($element['factionName'])) {
                            $element['factionName'] = self::getInfoField('factionID', $value, 'name');
                        }
                        break;
                    case 'weaponTypeID':
                        if (!isset($element['weaponTypeName'])) {
                            $element['weaponTypeName'] = self::getInfoField('typeID', $value, 'name');
                        }
                        break;
                    case 'locationID':
                    case 'itemID':
                        if (!isset($element['itemName'])) {
                            $element['itemName'] = self::getInfoField('itemID', $value, 'name');
                        }
                        if (!isset($element['locationName'])) {
                            $element['locationName'] = self::getInfoField('locationID', $value, 'name');
                        }
                        if (!isset($element['typeID'])) {
                            $element['typeID'] = self::getInfoField('itemID', $value, 'typeID');
                        }
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
                        if (!isset($element['fittable'])) {
                            $categoryID = isset($element['categoryID']) ? $element['categoryID'] : self::getInfoField('groupID', $element['groupID'], 'categoryID');
                            $element['fittable'] = ($categoryID == 7); // 7 - Fittable
                        }
                        break;
                    case 'solarSystemID':
                        if (!isset($element['solarSystemSecurity'])) {
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
                                $wspaceInfo = self::getWormholeSystemInfo($value);
                                if ($wspaceInfo) {
                                    $element['systemClass'] = $wspaceInfo['class'];
                                    $element['systemEffect'] = isset($wspaceInfo['effectName']) ? $wspaceInfo['effectName'] : null;
                                }
                            }
                        }
                        if (!isset($element['constellationID'])) {
                            $element['constellationID'] = Info::getInfoField('solarSystemID', $value, 'constellationID');
                        }
                        if (!isset($element['regionID'])) {
                            $element['regionID'] = Info::getInfoField('constellationID', $value, 'regionID');
                        }
                        $element['constellationName'] = Info::getInfoField('constellationID', $element['constellationID'], 'name');
                        $element['regionID'] = Info::getInfoField('regionID', $element['regionID'], 'name');
                        break;
                    case 'regionID':
                        if (!isset($element['regionName'])) {
                            $element['regionName'] = self::getInfoField('regionID', $value, 'name');
                        }
                        break;
                    case 'flag':
                        if (!isset($element['flagName'])) {
                            $element['flagName'] = self::getFlagName($value);
                        }
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
                return '#2c74e0';
            case 0.9:
                return '#3a9aeb';
            case 0.8:
                return '#4ecef8';
            case 0.7:
                return '#60d9a3';
            case 0.6:
                return '#71e554';
            case 0.5:
                return '#f3fd82';
            case 0.4:
                return '#DC6D07';
            case 0.3:
                return '#ce440f';
            case 0.2:
                return '#bc1117';
            case 0.1:
                return '#722020';
            default:
                return '#8d3264';
        }
    }

    public static $effectFitToSlot = array(
            '12' => 'High Slots',
            '13' => 'Mid Slots',
            '11' => 'Low Slots',
            '2663' => 'Rigs',
            '3772' => 'SubSystems',
            '87' => 'Drone Bay',
            '164' => 'Structure Service Slots',
            '172' => 'Structure Fuel',
            '179' => 'Frigate Bay',
            '180' => 'Core Room',
            );

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
            '134' => 'Mining Hold',
            '135' => 'Gas Hold',
            '136' => 'Mineral Hold',
            '137' => 'Salvage Hold',
            '138' => 'Specialized Ship Hold',
            '143' => 'Specialized Ammo Hold',
            '90' => 'Ship Hangar',
            '148' => 'Command Center Hold',
            '149' => 'Planetary Commodities Hold',
            '151' => 'Material Bay',
            '154' => 'Quafe Bay',
            '155' => 'Fleet Hangar',
            '156' => 'Hidden Modifiers',
            '158' => 'Fighter Bay',
            '159' => 'Fighter Tubes',
            '164' => 'Structure Service Slots',
            '172' => 'Structure Fuel',
            '173' => 'Deliveries',
            '174' => 'Crate Loot',
            '176' => 'Booster Bay',
            '177' => 'Subsystem Hold',
            '64' => 'Unlocked item, can be moved',
            '179' => 'Frigate Bay',
            '180' => 'Core Room',
            '181' => 'Ice Hold',
            '183' => 'Mobile Depot Bay',
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
            159 => array(159, 163), // Fighter Tubes
            164 => array(164, 171), // Structure services
            2663 => array(92, 98), // Rigs
            3772 => array(125, 132), // Subs
            179 => array(179, 179), // Frigate bay
            );

    public static function getFlagLocation($flag)
    {
        // Assuming Inferno Flags
        foreach (self::$infernoFlags as $infernoFlagGroup => $array) {
            $low = $array[0];
            $high = $array[1];
            if ($flag >= $low && $flag <= $high) {
                return $infernoFlagGroup;
            }
        }
        return 0;
    }

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
        $slotArray = [
            'lowSlots' => Info::getDogma($shipTypeID, 12),
            'medSlots' => Info::getDogma($shipTypeID, 13),
            'hiSlots' => Info::getDogma($shipTypeID, 14),
            'rigSlots' => Info::getDogma($shipTypeID, 1137)
        ];

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

    public static function getDogma($typeID, $attr_id)
    {  
        global $mdb, $redis;

        $p = $redis->get("zkb:dogma:$typeID:$attr_id");
        if ($p == "null") return null;
        if ($p != null) return (int) $p;

        $r = $mdb->find("information", ['type' => 'typeID', 'id' => $typeID], [], null, ['dogma_attributes' => [ '$elemMatch' => [ 'attribute_id' => $attr_id ] ]]);
        foreach ($r as $row ) {
            if (!isset($row['dogma_attributes'])) break;
            $row = $row['dogma_attributes'][0];
            $p = $row['value'];
            $redis->setex("zkb:dogma:$typeID:$attr_id", 3600, ($p == null ? "null" : $p));
            return $p;
        }
        $redis->setex("zkb:dogma:$typeID:$attr_id", 3600, "null");
        return null;
    }

    public static function findKillID($unixtime, $which) {
        global $mdb;

        if ($which != 'start') $unixtime += 59; // start at the end of the minute
        else $unixtime = $unixtime - ($unixtime % 60); // start at the beginning of the minute
        $starttime = $unixtime;
        do {
            $killID = $mdb->findField("killmails", "killID", ['dttm' => new MongoDate($unixtime)], ['killID' => ($which == 'start' ? 1 : -1)]);
            $unixtime += ($which == 'start' ? 1 : -1);
            if (abs($starttime - $unixtime) > 3600) break; // only check 1 hour worth of mails
        } while ($killID == null);
        return $killID;
    }

    public static $itemIDs = [];

    public static function getLocationID($solarSystemID, $position)
    {
        global $redis, $mdb;

        $x = $position['x'];
        $y = $position['y'];
        $z = $position['z'];

        if ($solarSystemID > 32000000 && $solarSystemID <= 32999999) return null;
        $systemLocations = $mdb->findDoc("locations", ['id' => $solarSystemID]);
        if ($systemLocations == null) {
            Log::log("Fetching fuzz map for system $solarSystemID");
            $raw = file_get_contents("https://www.fuzzwork.co.uk/api/mapdata.php?solarsystemid=$solarSystemID&format=json");
            $systemLocations = json_decode($raw, true);
            $save = ['id' => $solarSystemID, 'locations' => $systemLocations];
            $mdb->save("locations", $save);
            $systemLocations = $save;
        }
        $minDistance = null;
        $returnID = null;
        foreach ($systemLocations['locations'] as $row) { //$itemIDs as $itemID => $v) {
            $itemID = $row['itemid'];
            $distance = sqrt(pow($row['x'] - $x, 2) + pow($row['y'] - $y, 2) + pow($row['z'] - $z, 2));

            if ($minDistance === null) {
                // Initialize with the first value we find
                $minDistance = $distance;
                $returnID = $itemID;
            } elseif ($distance <= $minDistance) {
                // Overwrite with the lowest distance we found so far
                $minDistance = $distance;
                $returnID = $itemID;
            }
        }

        return $returnID;
        }

        static $DEV_NAMES = [
            'Andre',
            'Ben',
            'Bergthor',
            'Bergur',
            'Carl',
            'Chance',
            'Chris',
            'Euan',
            'Freyr',
            'Georg',
            'Hafsteinn',
            'Hinrik',
            'Hooper',
            'Huni',
            'Javier',
            'Jonathan',
            'Kasper',
            'Kristinn',
            'Mark',
            'Norbert',
            'Olafur',
            'Scott',
            'Sergey',
            'Skuli',
            'Steve',
            'Steven',
            'Svanhvit',
            'Tormod',
            'Willem'];


        public static function getMangledSystemName($solarSystemID, $charID)
        {
            mt_srand($solarSystemID + $charID);
            $i = mt_rand(0, sizeof(self::$DEV_NAMES) - 1);
            $name = self::$DEV_NAMES[$i];
            return str_rot13($name);
        }

    }
