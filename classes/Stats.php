<?php

class Stats
{
    public static function getTopIsk($parameters = array(), $allTime = false)
    {
        global $mdb;

        if (!isset($parameters['limit'])) {
            $parameters['limit'] = 5;
        }
        $parameters['orderBy'] = 'zkb.totalValue';

        $hashKey = 'getTopIsk:'.serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        $result = Kills::getKills($parameters);
        RedisCache::set($hashKey, $result, 300);

        return $result;
    }

    /**
     * @param string $groupByColumn
     */
    public static function getTop($groupByColumn, $parameters = array())
    {
        global $mdb, $debug, $longQueryMS;

        $hashKey = "Stats::getTop:$groupByColumn:".serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        if (isset($parameters['pastSeconds'])) {
            $killmails = $mdb->getCollection('oneWeek');
            if ($parameters['pastSeconds'] >= 604800) {
                unset($parameters['pastSeconds']);
            }
        } else {
            $killmails = $mdb->getCollection('killmails');
        }

        $query = MongoFilter::buildQuery($parameters);
        if (!$mdb->exists('killmails', $query)) {
            return [];
        }
        $andQuery = MongoFilter::buildQuery($parameters, false);

        if ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') {
            $keyField = "system.$groupByColumn";
        } else if ($groupByColumn != 'locationID') {
            $keyField = "involved.$groupByColumn";
        } else $keyField = $groupByColumn;

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
            $pipeline[] = ['$match' => [$type => $id, 'involved.isVictim' => false]];
        }
        $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
        $pipeline[] = ['$match' => $andQuery];
        $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$'.$keyField]]];
        $pipeline[] = ['$group' => ['_id' => '$_id.'.$groupByColumn, 'kills' => ['$sum' => 1]]];
        $pipeline[] = ['$sort' => ['kills' => -1]];
        if (!isset($parameters['nolimit'])) {
            $pipeline[] = ['$limit' => 10];
        }
        $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

        if (!$debug) {
            MongoCursor::$timeout = -1;
        }
        $result = $killmails->aggregateCursor($pipeline);
        $result = iterator_to_array($result);

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            Log::log("Aggregate Long query (${time}ms): $hashKey");
        }

        Info::addInfo($result);
        RedisCache::set($hashKey, $result, 3600);

        return $result;
    }

    public static function getDistinctCount($groupByColumn, $parameters = [])
    {
        global $mdb, $debug, $longQueryMS;

        $hashKey = "distinctCount::$groupByColumn:".serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        if ($parameters == []) {
            $type = ($groupByColumn == 'solarSystemID' || $groupByColumn == 'regionID') ? "system.$groupByColumn" : "involved.$groupByColumn";
            $result = $mdb->getCollection('oneWeek')->distinct($type);
            RedisCache::set($hashKey, sizeof($result), 3600);

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

        if (!$debug) {
            MongoCursor::$timeout = -1;
        }
        $result = $mdb->getCollection('oneWeek')->aggregateCursor($pipeline);
        $result = iterator_to_array($result);

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            Log::log("Distinct Long query (${time}ms): $hashKey");
        }

        $retValue = sizeof($result) == 0 ? 0 : $result[0]['value'];

        RedisCache::set($hashKey, $retValue, 3600);

        return $retValue;
    }

    // Collect active PVP stats
    public static function getActivePvpStats($parameters)
    {
        global $mdb;
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
            $row['type'] = $type;
            $row['count'] = $result;
            $activePvP[strtolower($type)] = $row;
        }
        $mongoParams = MongoFilter::buildQuery($parameters);
        $killCount = $mdb->getCollection('oneWeek')->count($mongoParams);
        if ($killCount > 0) {
            $activePvP['kills'] = ['type' => 'Total Kills', 'count' => $killCount];
        }

        return $activePvP;
    }
}
