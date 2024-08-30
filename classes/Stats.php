<?php

use cvweiss\redistools\RedisCache;

class Stats
{
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
    public static function getTop($groupByColumn, $parameters = array(), $cacheOverride = false, $addInfo = true)
    {
        global $mdb, $longQueryMS;

        $hashKey = "Stats::getTop:$groupByColumn:".serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($cacheOverride == false && $result != null) {
            return $result;
        }

        if (!isset($parameters['limit'])) $parameters['limit'] = 10;

        if (isset($parameters['pastSeconds']) && $parameters['pastSeconds'] <= 604800) {
            $killmails = $mdb->getCollection('oneWeek');
            if ($parameters['pastSeconds'] == 604800) {
                unset($parameters['pastSeconds']);
            }
        } else {
            $killmails = $mdb->getCollection('killmails');
        }

        $query = MongoFilter::buildQuery($parameters);
        /*if ($mdb->findOne('killmails', $query) === null) {
            return [];
        }*/
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
        $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$'.$keyField]]];
        $pipeline[] = ['$group' => ['_id' => '$_id.'.$groupByColumn, 'kills' => ['$sum' => 1]]];
        $pipeline[] = ['$sort' => ['kills' => -1]];
        if (!isset($parameters['nolimit'])) {
            $pipeline[] = ['$limit' => $parameters['limit']];
        }
        $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

        $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]);
        $result = $rr['result'];

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            global $uri;
            Log::log("getTop Long query (${time}ms): $hashKey $uri");
        }

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
            $result = $mdb->getCollection('oneWeek')->distinct($type);
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

        $result = $mdb->getCollection('oneWeek')->aggregateCursor($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true]);
        //MongoCursor::$timeout = -1;
        $result = iterator_to_array($result);

        $time = $timer->stop();
        if ($time > $longQueryMS) {
            Log::log("Distinct Long query (${time}ms): $hashKey");
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
        $killCount = $mdb->getCollection('oneWeek')->count($mongoParams);
        if ($killCount > 0) {
            $activePvP['kills'] = ['type' => 'Total Kills', 'count' => $killCount];
        }

        RedisCache::set($key, $activePvP, (($cacheOverride == null) ? 3600 : $cacheOverride));

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
