<?php

class zkbSearch
{
    public static $imageMap = [
        'typeID' => 'https://image.eveonline.com/types/%1$d/icon?size=32',
        'groupID' => 'https://image.eveonline.com/types/1/icon?size=32.png',
        'characterID' => 'https://image.eveonline.com/characters/%1$d/portrait?size=32',
        'corporationID' => 'https://image.eveonline.com/corporations/%1$d/logo?size=32',
        'allianceID' => 'https://image.eveonline.com/alliances/%1$d/logo?size=32',
        'factionID' => 'https://image.eveonline.com/corporations/%1$d/logo?size=32',
        'solarSystemID' => 'https://image.eveonline.com/types/3802/icon?size=32',
        'regionID' => 'https://zkillboard.com/img/region_64.png',
        'locationID' => 'https://image.eveonline.com/alliances/1/logo?size=32',
    ];

    public static function getResults($search, $entityType = null)
    {
        global $redis, $mdb;

        $search = strtolower($search);
        $regex = (substr($search, 0, 2) == "r:" && strlen($search) > 2) ? substr($search, 2) : null;
        $search = (substr($search, 0, 2) == "r:" && strlen($search) > 2) ? substr($search, 2) : $search;
        //$low = "[$search\x00";
        $low = $search;

        $exactMatch = [];
        $partialMatch = [];
        $types = ['typeID:flag', 'regionID', 'solarSystemID', 'factionID', 'allianceID', 'allianceID:flag', 'corporationID', 'corporationID:flag', 'characterID', 'typeID', 'groupID', 'locationID'];
        foreach ($types as $type) {
            if ($entityType != null && $entityType != $type) {
                continue;
            }

            $sub = $low;
            do {
                $result = unserialize($redis->hget("search:$type", $sub));
                if ($result == null) $result = [];
                if (sizeof($result) == 0) $sub = substr($sub, 0, strlen($sub) - 1);
            } while (sizeof($result) == 0 && strlen($sub) > 0);

            $searchType = $type;
            $type = str_replace(':flag', '', $type);
            foreach ($result as $row) {
                $split = explode("\x00", $row);
                if (sizeof($split) == 2 && substr($split[0], 0, strlen($search)) != $search) {
                    continue;
                }
                $id = $split[1];
                $info = Info::getInfo($type, $id);
                $name = $info['name'];
                $image = isset(self::$imageMap[$type]) ? self::$imageMap[$type] : '';
                $image = sprintf($image, $id);
                if ($searchType == 'typeID:flag') {
                    $searchType = 'ship';
                }
                if ($searchType == 'factionID') {
                    $searchType = 'faction';
                }
                if ($searchType == 'allianceID:flag') {
                    $searchType = 'alliance';
                }
                if ($searchType == 'corporationID:flag') {
                    $searchType = 'corporation';
                }
                if ($searchType == 'typeID') {
                    $searchType = 'item';
                }
                if ($searchType == 'groupID') {
                    $searchType = 'group';
                }
                if ($searchType == 'solarSystemID') {
                    $searchType = 'system';
                }
                if ($searchType == 'alliance' || $searchType == 'allianceID' || $searchType == 'corporation' || $searchType == 'corporationID') {
                    if (@$info['memberCount'] == 0) $name = "$name (Closed)";
                }
                if ($searchType == 'character' || $searchType == 'characterID') {
                    if (@$info['corporationID'] == 1000001) $name = "$name (recycled)";
                }
                if ($searchType == 'system') {
                    $regionID = Info::getInfoField('solarSystemID', $id, 'regionID');
                    $regionName = Info::getInfoField('regionID', $regionID, 'name');
                    $name = "$name ($regionName)";
                }
                if (strtolower($name) === $search) {
                    $exactMatch[] = ['id' => (int) $id, 'name' => $name, 'type' => str_replace('ID', '', $searchType), 'image' => $image];
                } else {
                    $partialMatch[] = ['id' => (int) $id, 'name' => $name, 'type' => str_replace('ID', '', $searchType), 'image' => $image];
                }
            }
        }

        $result = array_merge($exactMatch, $partialMatch);
        if (sizeof($result) > 15) {
            $result = array_slice($result, 0, 15);
        }

        return $result;
    }
}
