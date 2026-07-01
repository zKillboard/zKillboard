<?php

class zkbSearch
{
    public static $imageMap = [
        'typeID' => 'https://images.evetech.net/types/%1$d/icon?size=%2$d',
        'groupID' => 'https://image.eveonline.com/types/1/icon?size=%2$d',
        'characterID' => 'https://image.eveonline.com/characters/%1$d/portrait?size=%2$d',
        'corporationID' => 'https://image.eveonline.com/corporations/%1$d/logo?size=%2$d',
        'allianceID' => 'https://image.eveonline.com/alliances/%1$d/logo?size=%2$d',
        'factionID' => 'https://image.eveonline.com/corporations/%1$d/logo?size=%2$d',
        'solarSystemID' => 'https://zkillboard.com/img/nohus/systems/%1$d%3$s.png',
        'constellationID' => 'https://zkillboard.com/img/nohus/constellations/%1$d%3$s.png',
        'regionID' => 'https://zkillboard.com/img/nohus/regions/%1$d%3$s.png',
        'locationID' => 'https://image.eveonline.com/alliances/1/logo?size=%2$d',
    ];

    public static function getResults($search, $entityType = null, $imageSize = 32)
    {
        global $redis, $mdb;

        $rawSearch = (string) $search;
        $search = strtolower(preg_quote($search));
        $low = $search;

        $exactMatch = [];
        $exactMatchID = [];
        $partialMatch = [];
        $types = ['typeID', 'regionID', 'solarSystemID', 'factionID', 'allianceID', 'allianceID:flag', 'corporationID', 'corporationID:flag', 'characterID', 'groupID', 'locationID', 'constellationID'];
        foreach ($types as $type) {
            if ($entityType != null && $entityType != $type) {
                continue;
            }

            $sub = $low;
            do {
				$query =  ['type' => $type, 'l_name' => ['$regex' => "^$low"]];
				if ($type == "typeID") $query['published'] = true;
				$result = $mdb->find("information", $query, ['l_name' => 1], 5, ['l_name' => 1, 'id' => 1]);
                if ($result == null) $result = [];
                if (sizeof($result) == 0) $sub = substr($sub, 0, strlen($sub) - 1);
            } while (sizeof($result) == 0 && strlen($sub) > 0);

            if (sizeof($result) == 0 && strpos($low, ' ') !== false) {
                $terms = array_filter(explode(' ', $low), 'strlen');
                $wordPrefix = '^' . implode('.*\b', $terms);
                $query = ['type' => $type, 'l_name' => ['$regex' => $wordPrefix]];
                if ($type == "typeID") $query['published'] = true;
                $result = $mdb->find("information", $query, ['l_name' => 1], 5, ['l_name' => 1, 'id' => 1]);
                if ($result == null) $result = [];
            }

            if (trim($rawSearch) != '' && ($type == 'corporationID' || $type == 'allianceID')) {
                $tickerSearch = preg_quote(strtoupper(trim($rawSearch)));
                $tickerResult = $mdb->find("information", ['type' => $type, 'ticker' => ['$regex' => "^$tickerSearch"]], ['ticker' => 1], 5, ['ticker' => 1, 'id' => 1]);
                if ($tickerResult == null) $tickerResult = [];
                foreach ($tickerResult as &$row) $row['tickerMatch'] = true;
                unset($row);
                $result = array_merge($tickerResult, $result);
            }

            $type = str_replace(':flag', '', $type);
            $ids = [];
            foreach ($result as $row) {
                $searchType = $type;
                $id = $row['id'];
                if (array_search($id, $ids) !== false) continue;
                $ids[] = $id;

                $info = Info::getInfo($type, $id);
                $name = $info['name'] ?? 'Unknown';
                $ticker = trim((string) (@$info['ticker'] ?? ''));
                $image = isset(self::$imageMap[$type]) ? self::$imageMap[$type] : '';
                $localImageSuffix = $imageSize <= 32 ? '_32' : '';
                $image = sprintf($image, $id, $imageSize, $localImageSuffix);
                if (Util::endsWith($name, "Blueprint")) $image = str_replace("/icon", "/bp", $image);

                if ($searchType == 'typeID:flag' || $searchType == 'typeID') {
                    $searchType = in_array((int) @$info['categoryID'], [6, 65]) ? 'ship' : 'item';
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
                if (@$row['tickerMatch'] === true && $ticker != '') {
                    if ($searchType == 'alliance' || $searchType == 'allianceID') $name = "$name <$ticker>";
                    if ($searchType == 'corporation' || $searchType == 'corporationID') $name = "$name [$ticker]";
                }
                $pip = $searchType == 'ship' ? ($info['pip'] ?? '') : '';
                $searchResult = ['id' => (int) $id, 'name' => $name, 'type' => str_replace('ID', '', $searchType), 'image' => $image];
                if ($pip != '') $searchResult['pip'] = $pip;
                if (strtolower($name) === $search) {
                    $exactMatch[] = $searchResult;
                } else {
                    $partialMatch[] = $searchResult;
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
