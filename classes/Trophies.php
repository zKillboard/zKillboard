<?php

class Trophies
{
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
                $query = MongoFilter::buildQuery($filter);
                if (isset($filter['compare'])) {
                    $part2 = ['characterID' => $charID, 'isVictim' => !$filter['isVictim']];
                    $part2 = MongoFilter::buildQuery($part2);
                    $query = ['$and' => [$query, $part2]];
                }
                $count = $mdb->count('killmails', $query);
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

        $groups = $mdb->find('information', ['type' => 'groupID', 'cacheTime' => 3600], ['name' => 1]);

        foreach ($groups as $row) {
            if (@$row['categoryID'] != 6 || @$row['published'] != true) continue;

            $groupID = (int) $row['id'];
            $count = $mdb->count('information', ['groupID' => $groupID]);
            if ($count == 0) continue;

            $maxLevelCount += 2;

            $groupName = $row['name'];
            $a = in_array(substr(strtolower($groupName), 0, 1), ['a', 'e', 'i', 'o', 'u']) ? 'an' : 'a';

            $values = @$stats['groups'][$groupID];
            $level = self::getLevel(@$values['shipsDestroyed']);
            $levelCount += ($level > 0 ? 1 : 0);
            $trophies['trophies']['Killed']["Kill $a $groupName"] = ['met' => (@$values['shipsDestroyed'] > 0), 'level' => $level, 'value' => (int) @$values['shipsDestroyed'], 'next' => static::getNext(@$values['shipsDestroyed']), 'link' => "/character/$charID/kills/reset/group/$groupID/losses/"];

            $level = static::getLevel(@$values['shipsLost'], 5);
            $levelCount += ($level > 0 ? 1 : 0);
            $trophies['trophies']['Lost']["Lose $a $groupName"] = ['met' => (@$values['shipsLost'] > 0), 'level' => $level, 'value' => (int) @$values['shipsLost'], 'next' => static::getNext(@$values['shipsLost'], 5), 'link' => "/character/$charID/losses/group/$groupID/"];
        }

        $trophies['levelCount'] = $levelCount;
        $trophies['maxLevelCount'] = $maxLevelCount;
        $trophies['boxes'] = floor(($levelCount / $maxLevelCount) * 5);
        $trophies['completedPct'] = number_format(($levelCount / $maxLevelCount) * 100, 0);

        return $trophies;
    }

    public static function addTrophy(&$trophies, $condition, $conditionMet, $value, $noNext = false, $link = null)
    {
        $level = static::getLevel($value);
        $arr = ['met' => $conditionMet, 'level' => $level, 'value' => $value, 'next' => static::getNext($value), 'noNext' => $noNext];
        if (isset($condition['link'])) {
            $arr['link'] = $condition['link'];
        }
        $trophies['trophies'][$condition['type']][$condition['name']] = $arr;

        return $conditionMet ? 1 : 0;
    }

    public static function getLevel($value, $divider = 1)
    {
        $value = (int) $value;
        if ($value >= 5) return 5;
        if ($value >= 4) return 4;
        if ($value >= 3) return 3;
        if ($value >= 2) return 2;
        if ($value >= 1) return 1;
        return 0;

        if ($value == 0) return 0;
        return 5;
        if ($value <= 0) {
            return 0;
        }
        if ($value < (5 / $divider)) {
            return 1;
        }
        if ($value < (10 / $divider)) {
            return 2;
        }
        if ($value < (15 / $divider)) {
            return 3;
        }
        if ($value < (20 / $divider)) {
            return 4;
        }

        return 5;
    }

    public static function getNext($value, $divider = 1)
    {
        return $value + 1;
    }
}
