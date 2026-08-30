<?php

class Trophies
{
    private static $shipGroups = null;
    private static $groupIDsWithTypes = null;
    private static $regionalTargets = null;
    private static $regionalTotals = null;

    // isk values
    // be in tournament region
    // freighter burn 

    public static $conditions = [
        ['type' => 'General', 'name' => 'Get a solo kill', 'stats' => ['field' => 'soloKills', 'value' => 1], 'rank' => 2, 'link' => '../solo/kills/'],
        ['type' => 'General', 'name' => 'Kill Kill Kill', 'stats' => ['field' => 'shipsDestroyed', 'value' => 1], 'link' => '../kills/'],
        ['type' => 'General', 'name' => 'Didn\'t want that ship anyway (Losses)', 'stats' => ['field' => 'shipsLost', 'value' => 1], 'link' => '../losses/'],
        ['type' => 'Special', 'name' => 'Concordokken! Get concorded', 'filter' => ['isVictim' => false, 'corporationID' => 1000125, 'compare' => true], 'rank' => '1', 'link' => '../losses/reset/corporation/1000125/kills/'],
        ['type' => 'Special', 'name' => 'What did you do?! Get killed by a CCP dev', 'filter' => ['isVictim' => false, 'corporationID' => 109299958, 'compare' => true], 'rank' => 5, 'link' => '../losses/reset/corporation/109299958/kills/'],
        ['type' => 'Special', 'name' => 'Banhammer incoming! Kill a CCP dev', 'filter' => ['isVictim' => true, 'corporationID' => 109299958, 'compare' => true], 'rank' => 5, 'link' => '../kills/reset/corporation/109299958/losses/'],
        ['type' => 'General', 'name' => 'Get a kill in High Sec', 'filter' => ['characterID' => '?', 'isVictim' => false, 'highsec' => true], 'rank' => 1, 'link' => '../kills/highsec/'],
        ['type' => 'General', 'name' => 'Get a kill in Low Sec', 'filter' => ['characterID' => '?', 'isVictim' => false, 'lowsec' => true], 'rank' => 5, 'link' => '../kills/lowsec/'],
        ['type' => 'General', 'name' => 'Get a kill in Null Sec', 'filter' => ['characterID' => '?', 'isVictim' => false, 'nullsec' => true], 'rank' => 25, 'link' => '../kills/nullsec/'],
        ['type' => 'General', 'name' => 'Get a kill in Anoikis (wh space)', 'filter' => ['characterID' => '?', 'isVictim' => false, 'w-space' => true], 'rank' => 125, 'link' => '../kills/w-space/'],
        ['type' => 'General', 'name' => 'Get a kill in Pochven', 'filter' => ['characterID' => '?', 'isVictim' => false, 'regionID' => 10000070], 'rank' => 125, 'link' => '../region/10000070/'],
        ['type' => 'Special', 'name' => 'Participate in a tournament', 'filter' => ['regionID' => 10000004, 'characterID' => '?'], 'rank' => 5000, 'link' => '../regionID/10000004'],
        ['type' => 'Special', 'name' => 'GANKED: suicide inspired killmail', 'filter' => ['characterID' => '?', 'isVictim' => false, 'ganked' => true], 'rank' => 25, 'link' => '../ganked/'],
        ['type' => 'Special', 'name' => 'Ganktastic Bonus: Freighters must die', 'statGroup' => ['groupID' => 513, 'field' => 'shipsDestroyed', 'value' => 1], 'rank' => 625, 'link' => '../reset/group/513/losses/'],

        ['type' => 'Special', 'name' => 'Backstab Special: You awoxed!', 'filter' => ['characterID' => '?', 'isVictim' => false, 'awox' => true], 'rank' => 25, 'link' => '../awox/1/kills/'],
        ['type' => 'Special', 'name' => 'My Back Hurts: Got awoxed!', 'filter' => ['characterID' => '?', 'isVictim' => true, 'awox' => true], 'rank' => 25, 'link' => '../awox/1/losses/'],
        ];

    public static $regionalConditions = [
        ['type' => 'Regional Kills', 'name' => 'Kill in every High Sec system', 'field' => 'solarSystemID', 'label' => 'loc:highsec', 'isVictim' => false, 'link' => '../kills/highsec/'],
        ['type' => 'Regional Kills', 'name' => 'Kill in every Low Sec system', 'field' => 'solarSystemID', 'label' => 'loc:lowsec', 'isVictim' => false, 'link' => '../kills/lowsec/'],
        ['type' => 'Regional Kills', 'name' => 'Kill in every Null Sec system', 'field' => 'solarSystemID', 'label' => 'loc:nullsec', 'isVictim' => false, 'link' => '../kills/nullsec/'],
        ['type' => 'Regional Kills', 'name' => 'Kill in every Pochven system', 'field' => 'solarSystemID', 'label' => 'loc:pochven', 'isVictim' => false, 'link' => '../region/10000070/'],
        ['type' => 'Regional Kills', 'name' => 'Kill in every W-Space system', 'field' => 'solarSystemID', 'label' => 'loc:w-space', 'isVictim' => false, 'link' => '../kills/w-space/'],
        ['type' => 'Regional Kills', 'name' => 'Get a kill in Thera', 'field' => 'solarSystemID', 'systemID' => 31000005, 'isVictim' => false, 'link' => '../system/31000005/'],
        ['type' => 'Regional Kills', 'name' => 'Kill in every Drifter system', 'field' => 'solarSystemID', 'label' => 'loc:drifter', 'isVictim' => false, 'link' => '../kills/'],
        ['type' => 'Regional Kills', 'name' => 'Get a kill in Zarzakh', 'field' => 'solarSystemID', 'label' => 'loc:zarzakh', 'isVictim' => false, 'link' => '../system/30100000/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every High Sec region', 'field' => 'regionID', 'label' => 'loc:highsec', 'isVictim' => true, 'link' => '../losses/highsec/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every Low Sec region', 'field' => 'regionID', 'label' => 'loc:lowsec', 'isVictim' => true, 'link' => '../losses/lowsec/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every Null Sec region', 'field' => 'regionID', 'label' => 'loc:nullsec', 'isVictim' => true, 'link' => '../losses/nullsec/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every Pochven system', 'field' => 'solarSystemID', 'label' => 'loc:pochven', 'isVictim' => true, 'link' => '../region/10000070/losses/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every W-Space region', 'field' => 'regionID', 'label' => 'loc:w-space', 'isVictim' => true, 'link' => '../losses/w-space/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in Thera', 'field' => 'solarSystemID', 'systemID' => 31000005, 'isVictim' => true, 'link' => '../system/31000005/losses/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in every Drifter system', 'field' => 'solarSystemID', 'label' => 'loc:drifter', 'isVictim' => true, 'link' => '../losses/'],
        ['type' => 'Regional Losses', 'name' => 'Lose a ship in Zarzakh', 'field' => 'solarSystemID', 'label' => 'loc:zarzakh', 'isVictim' => true, 'link' => '../system/30100000/losses/'],
    ];

    public static function getTrophies($charID)
    {
        global $mdb;

        $charID = (int) $charID;
        $type = 'characterID';

        $stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => $charID]);
        $trophies = [];
        $maxLevelCount = 0;
        $levelCount = 0;

        foreach (static::$conditions as $condition) {
            $maxLevelCount += 5;
            if (isset($condition['filter'])) {
                $filter = $condition['filter'];
                if (isset($filter['characterID'])) {
                    $filter['characterID'] = $charID;
                }

                $count = static::getFilterCountFromStats($stats, $filter);
                if ($count === null) {
                    $query = MongoFilter::buildQuery($filter);
                    if (isset($filter['compare'])) {
                        $part2 = ['characterID' => $charID, 'isVictim' => !$filter['isVictim']];
                        $part2 = MongoFilter::buildQuery($part2);
                        $query = ['$and' => [$query, $part2]];
                    }
                    $count = $mdb->count('killmails', $query);
                }

                static::addTrophy($trophies, $condition, $count > 0, $count);
                $levelCount += self::getLevel($count);

            }
            if (isset($condition['stats'])) {
                $field = $condition['stats']['field'];
                $value = $condition['stats']['value'];
                $met = @$stats[$field] >= $value;
                static::addTrophy($trophies, $condition, $met, (int) @$stats[$field]);
                $levelCount += self::getLevel($value);
            }
            if (isset($condition['statGroup'])) {
                $group = @$stats['groups'][$condition['statGroup']['groupID']];
                $field = $condition['statGroup']['field'];
                $value = $condition['statGroup']['value'];
                static::addTrophy($trophies, $condition, @$group[$field] >= $value, @$group[$field]);
                $levelCount += self::getLevel($value);
            }
        }

        if (static::$regionalTotals === null) {
            $universeValues = [];
            $systems = $mdb->getCollection('information')->find(
                ['type' => 'solarSystemID'],
                ['projection' => ['_id' => 0, 'id' => 1, 'regionID' => 1, 'secStatus' => 1]]
            );
            foreach ($systems as $system) {
                $systemID = (int) ($system['id'] ?? 0);
                $regionID = (int) ($system['regionID'] ?? 0);
                $security = (float) ($system['secStatus'] ?? 0);
                $systemLabels = [];
                if ($security >= 0.45) $systemLabels[] = 'loc:highsec';
                if ($security < 0.45 && $security >= 0) $systemLabels[] = 'loc:lowsec';
                if ($security < 0 && $regionID < 11000001 && $regionID != 10000070 && $regionID != 10001000) $systemLabels[] = 'loc:nullsec';
                if ($regionID == 10000070) $systemLabels[] = 'loc:pochven';
                if ($regionID >= 11000000 && $regionID < 12000000) $systemLabels[] = 'loc:w-space';
                if ($regionID == 11000033) $systemLabels[] = 'loc:drifter';
                if ($systemID == 30100000) $systemLabels[] = 'loc:zarzakh';
                foreach ($systemLabels as $label) {
                    if ($systemID > 0) $universeValues[$label . ':solarSystemID'][$systemID] = true;
                    if ($regionID > 0) $universeValues[$label . ':regionID'][$regionID] = true;
                }
                if ($systemID == 31000005) $universeValues['31000005:solarSystemID'][$systemID] = true;
            }
            static::$regionalTargets = $universeValues;
            static::$regionalTotals = array_map('count', $universeValues);
        }

        $labels = array_values(array_unique(array_column(static::$regionalConditions, 'label')));
        $rows = $mdb->getCollection('killmails')->aggregate([
            ['$match' => ['involved.characterID' => $charID, '$or' => [
                ['labels' => ['$in' => $labels]],
                ['system.solarSystemID' => ['$in' => [30100000, 31000005]]],
            ]]],
            ['$unwind' => '$involved'],
            ['$match' => ['involved.characterID' => $charID]],
            ['$set' => ['coverageLabels' => ['$concatArrays' => [
                ['$ifNull' => ['$labels', []]],
                ['$cond' => [['$eq' => ['$system.regionID', 11000033]], ['loc:w-space'], []]],
                ['$cond' => [['$eq' => ['$system.solarSystemID', 30100000]], ['loc:zarzakh'], []]],
                ['$cond' => [['$eq' => ['$system.solarSystemID', 31000005]], [31000005], []]],
            ]]]],
            ['$unwind' => '$coverageLabels'],
            ['$match' => ['coverageLabels' => ['$in' => array_merge($labels, [31000005])]]],
            ['$group' => ['_id' => [
                'label' => '$coverageLabels',
                'isVictim' => '$involved.isVictim',
                'solarSystemID' => '$system.solarSystemID',
                'regionID' => '$system.regionID',
            ]]],
        ], ['allowDiskUse' => true]);
        $regionalValues = [];
        foreach ($rows as $row) {
            $row = (array) $row['_id'];
            foreach (['solarSystemID', 'regionID'] as $field) {
                $value = (int) ($row[$field] ?? 0);
                if ($value > 0) $regionalValues[$row['label'] . ':' . (int) $row['isVictim'] . ':' . $field][$value] = true;
            }
        }

        foreach (static::$regionalConditions as $condition) {
            $coverage = $condition['systemID'] ?? $condition['label'];
            $key = $coverage . ':' . (int) $condition['isVictim'] . ':' . $condition['field'];
            $targetKey = $coverage . ':' . $condition['field'];
            $count = count(array_intersect_key($regionalValues[$key] ?? [], static::$regionalTargets[$targetKey] ?? []));
            $total = (int) (static::$regionalTotals[$targetKey] ?? 0);
            $met = $total > 0 && $count >= $total;
            $level = $count > 0 && $total > 0 ? min(5, (int) ceil(($count / $total) * 5)) : 0;
            $trophies['trophies'][$condition['type']][$condition['name']] = [
                'met' => $met,
                'level' => $level,
                'value' => $count,
                'total' => $total,
                'link' => $condition['link'],
            ];
            $levelCount += $level;
            $maxLevelCount += 5;
        }

        $groups = static::getShipGroups();

        foreach ($groups as $row) {
            $groupID = (int) $row['id'];
            if (!isset(static::$groupIDsWithTypes[$groupID])) continue;

            $maxLevelCount += 2;

            $groupName = $row['name'];
            $a = in_array(substr(strtolower($groupName), 0, 1), ['a', 'e', 'i', 'o', 'u']) ? 'an' : 'a';

            $values = @$stats['groups'][$groupID];
            $level = self::getLevel(@$values['shipsDestroyed'], 5);
            $levelCount += ($level > 0 ? 1 : 0);
            $trophies['trophies']['Killed']["Kill $a $groupName"] = ['met' => (@$values['shipsDestroyed'] > 0), 'level' => $level, 'value' => (int) @$values['shipsDestroyed'], 'next' => static::getNext(@$values['shipsDestroyed'], 5), 'link' => "/character/$charID/kills/reset/group/$groupID/losses/"];

            $level = static::getLevel(@$values['shipsLost'], 2);
            $levelCount += ($level > 0 ? 1 : 0);
            $trophies['trophies']['Lost']["Lose $a $groupName"] = ['met' => (@$values['shipsLost'] > 0), 'level' => $level, 'value' => (int) @$values['shipsLost'], 'next' => static::getNext(@$values['shipsLost'], 2), 'link' => "/character/$charID/losses/group/$groupID/"];
        }

        $orderedTrophies = [];
        foreach (['General', 'Special', 'Killed', 'Lost', 'Regional Kills', 'Regional Losses'] as $category) {
            if (isset($trophies['trophies'][$category])) $orderedTrophies[$category] = $trophies['trophies'][$category];
        }
        $trophies['trophies'] = $orderedTrophies;

        $trophies['levelCount'] = $levelCount;
        $trophies['maxLevelCount'] = $maxLevelCount;
        $trophies['boxes'] = floor(($levelCount / $maxLevelCount) * 5);
        $trophies['completedPct'] = number_format(($levelCount / $maxLevelCount) * 100, 0);

        return $trophies;
    }

    private static function getShipGroups()
    {
        global $mdb, $redis;

        if (static::$shipGroups !== null && static::$groupIDsWithTypes !== null) {
            return static::$shipGroups;
        }

        static::$shipGroups = $mdb->find('information', ['type' => 'groupID', 'categoryID' => 6, 'published' => true, 'cacheTime' => 3600], ['name' => 1], null, ['id' => 1, 'name' => 1]);
        static::$groupIDsWithTypes = [];

        $distinctOptions = [];
        if (php_sapi_name() !== 'cli') $distinctOptions['maxTimeMS'] = 30000;
        $groupIDs = (string) $redis->get("zkb:information:shipgroups");
        if ($groupIDs != "") {
            $groupIDs = json_decode($groupIDs, true);
        } else {
            $groupIDs = $mdb->getCollection('information')->distinct('groupID', ['type' => 'typeID', 'groupID' => ['$exists' => true]], $distinctOptions);
            $redis->setex("zkb:information:shipgroups", 9000, json_encode($groupIDs, true));
        }
        foreach ($groupIDs as $groupID) {
            if (((int) $groupID) > 0) static::$groupIDsWithTypes[(int) $groupID] = true;
        }

        return static::$shipGroups;
    }

    private static function getFilterCountFromStats($stats, $filter)
    {
        if (!is_array($filter)) {
            return null;
        }
        if (isset($filter['compare'])) {
            return null;
        }
        if (isset($filter['characterID']) && $filter['characterID'] !== '?' && (int) $filter['characterID'] <= 0) {
            return null;
        }

        $isVictim = isset($filter['isVictim']) ? (bool) $filter['isVictim'] : false;
        $field = $isVictim ? 'shipsLost' : 'shipsDestroyed';

        if (!empty($filter['highsec'])) {
            return (int) @$stats['labels']['loc:highsec'][$field];
        }
        if (!empty($filter['lowsec'])) {
            return (int) @$stats['labels']['loc:lowsec'][$field];
        }
        if (!empty($filter['nullsec'])) {
            return (int) @$stats['labels']['loc:nullsec'][$field];
        }
        if (!empty($filter['w-space'])) {
            return (int) @$stats['labels']['loc:w-space'][$field];
        }
        if (!empty($filter['ganked'])) {
            return (int) @$stats['labels']['ganked'][$field];
        }
        if (!empty($filter['awox'])) {
            return (int) @$stats['labels']['awox'][$field];
        }

        return null;
    }

    public static function addTrophy(&$trophies, $condition, $conditionMet, $value)
    {
        $level = static::getLevel($value);
        $arr = ['met' => $conditionMet, 'level' => $level, 'value' => $value, 'next' => static::getNext($value)];
        if (isset($condition['link'])) {
            $arr['link'] = $condition['link'];
        }
        $trophies['trophies'][$condition['type']][$condition['name']] = $arr;

        return $conditionMet ? 1 : 0;
    }

    public static function getLevel($value, $base = null)
    {
        $value = (int) $value;
        if ($value <= 0) return 0;
        if ($base === null) return min(5, $value);

        $base = max(2, (int) $base);
        $level = 1;
        $next = $base;
        while ($level < 5 && $value >= $next) {
            ++$level;
            $next *= $base;
        }
        return $level;
    }

    public static function getNext($value, $base = null)
    {
        $level = static::getLevel($value, $base);
        if ($level >= 5) return null;
        return $base === null ? $level + 1 : pow(max(2, (int) $base), $level);
    }
}
