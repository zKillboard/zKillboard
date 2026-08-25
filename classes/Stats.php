<?php

use cvweiss\redistools\RedisCache;

class Stats
{
    public static function getCharacterTags($statistics, $detail)
    {
        $statistics = is_object($statistics ?? null) ? (array) $statistics : ($statistics ?? []);
        $detail = is_object($detail ?? null) ? (array) $detail : ($detail ?? []);
        $activityTags = is_object($statistics['activityTags'] ?? null) ? (array) $statistics['activityTags'] : ($statistics['activityTags'] ?? []);
        $tags = [];
        foreach ([
            'blops' => ['label' => 'BLOPS', 'color' => '#4f246b', 'ship' => 'Black Ops battleship'],
            'logi' => ['label' => 'LOGI', 'color' => '#2f5f55', 'ship' => 'logistics cruiser or frigate'],
            'capital' => ['label' => 'CAPITAL', 'color' => '#6e331f', 'ship' => 'carrier, dreadnought, force auxiliary, supercarrier, or titan'],
            'super' => ['label' => 'SUPER', 'color' => '#6b2f45', 'ship' => 'supercarrier'],
            'titan' => ['label' => 'TITAN', 'color' => '#604515', 'ship' => 'titan'],
        ] as $tag => $definition) {
            $count = (int) ($activityTags[$tag] ?? 0);
            if ($count > 0) $tags[] = [
                'label' => $definition['label'],
                'count' => $count,
                'color' => $definition['color'],
                'title' => "$count past-90-day combat appearances in a {$definition['ship']}",
            ];
        }

        $birthday = strtotime((string) ($detail['birthday'] ?? ''));
        $ageDays = $birthday === false ? -1 : (int) floor((time() - $birthday) / 86400);
        $recentMetrics = $statistics['rankings']['recent']['all']['metrics'] ?? [];
        $recentKills = (int) ($recentMetrics['shipsDestroyed'] ?? 0);
        $recentLosses = (int) ($recentMetrics['shipsLost'] ?? 0);
        if ($ageDays >= 0 && $ageDays < 180 && $recentLosses > $recentKills && (int) ($activityTags['capital'] ?? 0) == 0 && (int) ($activityTags['super'] ?? 0) == 0 && (int) ($activityTags['titan'] ?? 0) == 0 && empty($statistics['cyno']) && empty($statistics['bait'])) {
            $tags[] = [
                'label' => 'ROOKIE',
                'color' => '#24536f',
                'title' => "$ageDays days old with $recentKills PvP kills, $recentLosses losses in the past 90 days, and no CAPITAL, SUPER, TITAN, CYNO, or BAIT label",
            ];
        }
        return $tags;
    }

    public static function getTopIsk($parameters = array(), $allTime = false, $fittedValue = false, $cacheOverride = null)
    {
        if (!isset($parameters['limit'])) {
            $parameters['limit'] = 5;
        }
        if ($fittedValue) $parameters['orderBy'] = 'zkb.fittedValue';
        else $parameters['orderBy'] = 'zkb.totalValue';

        $hashKey = 'getTopIsk:'.serialize($parameters);
        $result = ($cacheOverride == null) ? RedisCache::get($hashKey) : null;
        if ($result != null) {
            return $result;
        }

        $result = Kills::getKills($parameters, true, true, true);
        RedisCache::set($hashKey, $result, (($cacheOverride == null) ? 900 : $cacheOverride));

        return $result;
    }

    /**
     * @param string $groupByColumn
     */
    public static function getTop($groupByColumn, $parameters = array(), $cacheOverride = false, $addInfo = true, $sortBy = 'kills')
    {
        global $mdb, $longQueryMS;

        $sortBy = $sortBy == 'isk' ? 'isk' : 'kills';
        $hashKey = "Stats::getTop:$groupByColumn:$sortBy:".serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($cacheOverride == false && $result != null) {
            return $result;
        }

        if (!isset($parameters['limit'])) $parameters['limit'] = 10;
        $parameters['limit'] = $parameters['limit'] * 5;

        if (isset($parameters['pastSeconds']) && $parameters['pastSeconds'] <= 604800) {
            $killmails = $mdb->getCollection('oneWeek');
            if ($parameters['pastSeconds'] == 604800) {
                unset($parameters['pastSeconds']);
            }
        } else {
            $killmails = $mdb->getCollection('killmails');
        }
        $maxTimeMS = $parameters['maxTimeMS'] ?? -1;
        unset($parameters['maxTimeMS']);

        $query = MongoFilter::buildQuery($parameters);
        $andQuery = MongoFilter::buildQuery($parameters, false);

        if ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') {
            $keyField = "system.$groupByColumn";
        } elseif ($groupByColumn != 'locationID') {
            $keyField = "involved.$groupByColumn";
        } else {
            $keyField = $groupByColumn;
        }

        $id = $type = null;
        if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
            foreach ($parameters as $k => $v) {
                if (strpos($k, 'ID') === false) {
                    continue;
                }
                if (!is_array($v) || sizeof($v) < 1) {
                    continue;
                }
                $id = $v[0];
                if ($k != 'solarSystemID' && $k != 'regionID') {
                    $type = "involved.$k";
                } else {
                    $type = "system.$k";
                }
            }
        }

        $timer = new Timer();
        $pipeline = [];
        $pipeline[] = ['$match' => $query];
        if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
            $pipeline[] = ['$unwind' => '$involved'];
        }
        if ($type != null && $id != null) {
            //$pipeline[] = ['$match' => [$type => $id, 'involved.isVictim' => false]];
        }
        $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
        $pipeline[] = ['$match' => [$keyField => ['$ne' => 0]]];
        $pipeline[] = ['$match' => $andQuery];
        $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$'.$keyField], 'isk' => ['$first' => '$zkb.totalValue']]];
        $pipeline[] = ['$group' => ['_id' => '$_id.'.$groupByColumn, 'kills' => ['$sum' => 1], 'isk' => ['$sum' => '$isk']]];
        $pipeline[] = ['$sort' => ($sortBy == 'isk' ? ['isk' => -1, 'kills' => -1] : ['kills' => -1, 'isk' => -1])];
        if (!isset($parameters['nolimit'])) {
            $pipeline[] = ['$limit' => $parameters['limit']];
        }
        $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, 'isk' => 1, '_id' => 0]];

        $options = ['batchSize' => 1000, 'allowDiskUse' => true, 'noCursorTimeout' => true];
        if (php_sapi_name() !== 'cli') $options['maxTimeMS'] = 35000; // web requests should not run longer than 35 seconds

        $rr = $killmails->aggregate($pipeline, $options);
        $result = iterator_to_array($rr);

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            global $uri;
            // Util::zout("getTop Long query (${time}ms): $hashKey $uri");
        }

        $result = Util::removeDQed($result, $groupByColumn, $parameters['limit'] / 5);

        if ($addInfo) Info::addInfo($result);
        RedisCache::set($hashKey, $result, isset($parameters['cacheTime']) ? $parameters['cacheTime'] : 900);

        return $result;
    }

    public static function getDistinctCount($groupByColumn, $parameters = [])
    {
        global $mdb, $longQueryMS;

        $hashKey = "distinctCount::$groupByColumn:".serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        if ($parameters == []) {
            $type = ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') ? "system.$groupByColumn" : "involved.$groupByColumn";
            $distinctOptions = [];
            if (php_sapi_name() !== 'cli') $distinctOptions['maxTimeMS'] = 30000;
            $result = $mdb->getCollection('oneWeek')->distinct($type, [], $distinctOptions);
            RedisCache::set($hashKey, sizeof($result), 900);

            return sizeof($result);
        }

        $query = MongoFilter::buildQuery($parameters);
        if (!$mdb->exists('oneWeek', $query)) {
            return [];
        }
        $andQuery = MongoFilter::buildQuery($parameters, false);

        if ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') {
            $keyField = "system.$groupByColumn";
        } else {
            $keyField = "involved.$groupByColumn";
        }

        $id = $type = null;
        if ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') {
            $type = "system.$groupByColumn";
        }
        if ($type == null) {
            $type = "involved.$groupByColumn";
        }

        $timer = new Timer();
        $pipeline = [];
        $pipeline[] = ['$match' => $query];
        if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID') {
            $pipeline[] = ['$unwind' => '$involved'];
        }
        if ($type != null && $id != null) {
            $pipeline[] = ['$match' => [$type => $id]];
        }
        $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
        $pipeline[] = ['$match' => $andQuery];
        $pipeline[] = ['$group' => ['_id' => '$'.$type, 'foo' => ['$sum' => 1]]];
        $pipeline[] = ['$group' => ['_id' => 'total', 'value' => ['$sum' => 1]]];

        $options = ['allowDiskUse' => true, 'noCursorTimeout' => true];
        if (php_sapi_name() !== 'cli') $options['maxTimeMS'] = 65000; // web requests should not run longer than 65 seconds

        $result = $mdb->getCollection('oneWeek')->aggregate($pipeline, $options);
        $result = iterator_to_array($result);

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            // Util::zout("Distinct Long query (${time}ms): $hashKey");
        }

        $retValue = sizeof($result) == 0 ? 0 : $result[0]['value'];

        RedisCache::set($hashKey, $retValue, 900);

        return $retValue;
    }

    // Collect active PVP stats
    public static function getActivePvpStats($parameters, $cacheOverride = null)
    {
        global $mdb;

        $parameters['npc'] = false;
        $key = 'stats:activepvp:'.serialize($parameters);
        $activePvP = ($cacheOverride == null) ? RedisCache::get($key) : null;
        if ($activePvP != null) {
            return $activePvP;
        }

        $types = ['characterID', 'corporationID', 'allianceID', 'shipTypeID', 'solarSystemID', 'regionID'];
        $activePvP = [];
        foreach ($types as $type) {
            $result = self::getDistinctCount($type, $parameters);
            if ((int) $result <= 1) {
                continue;
            }
            $type = str_replace('ID', '', $type);
            if ($type == 'shipType') {
                $type = 'Ship';
            } elseif ($type == 'solarSystem') {
                $type = 'System';
            } else {
                $type = ucfirst($type);
            }
            $type = $type.'s';

            $row = array();
            $row['type'] = $type;
            $row['count'] = $result;
            $activePvP[strtolower($type)] = $row;
        }
        $mongoParams = MongoFilter::buildQuery($parameters);
        $killCount = $mdb->count('oneWeek', $mongoParams);
        if ($killCount > 0) {
            $activePvP['kills'] = ['type' => 'Total Kills', 'count' => $killCount];
        }

        RedisCache::set($key, $activePvP, (($cacheOverride == null) ? 80000 : $cacheOverride));

        return $activePvP;
    }

    public static function getSupers($key, $id)
    {
        $data = array();
        $parameters = [$key => (int) $id, 'groupID' => 30, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
        $data['titans']['data'] = self::getTop('characterID', $parameters);
        $data['titans']['title'] = 'Titans';

        $parameters = [$key => (int) $id, 'groupID' => 659, 'isVictim' => false, 'pastSeconds' => (86400 * 90), 'nolimit' => true];
        $data['supercarriers']['data'] = self::getTop('characterID', $parameters);
        $data['supercarriers']['title'] = 'Supercarriers';

        Info::addInfo($data);

        return $data;
    }
}
