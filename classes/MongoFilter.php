<?php

use cvweiss\redistools\RedisCache;

class MongoFilter
{
    public static function getKills($parameters, $buildQuery = true)
    {
        global $mdb;

        $limit = max(1, min(200, isset($parameters['limit']) ? (int) $parameters['limit']  : 50));
        $sortDirection = isset($parameters['orderDirection']) ? ($parameters['orderDirection'] == 'asc' ? 1 : -1)  : -1;
        if (isset($parameters['startTime'])) {
            $sortDirection = 'asc';
        }
        $sortKey = isset($parameters['orderBy']) ? $parameters['orderBy'] : 'killID';
        $page = isset($parameters['page']) ? ($parameters['page'] == 0 ? 0 : $parameters['page'] - 1) : 0;

        $hashKey = 'MongoFilter::getKills:'.serialize($parameters).":$limit:$page:$sortKey:$sortDirection:$buildQuery";
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        $collection = 'killmails';
        if (isset($parameters['pastSeconds']) && $parameters['pastSeconds'] <= 608400) {
            $collection = 'oneWeek';
            if ($parameters['pastSeconds'] == 608400) unset($parameters['pastSeconds']);
        }

        // Build the query parameters
        $query = $buildQuery ? self::buildQuery($parameters) : $parameters;

        // Start the query
        $killmails = $mdb->getCollection($collection);
        $cursor = $killmails->find($query, ['_id' => 0, 'killID' => 1])->timeout(3600000);

        // Apply the sort order
        $cursor->sort([$sortKey => $sortDirection]);

        self::applyPage($cursor, $page, $limit);
        self::applyLimit($cursor, $parameters, $limit);

        $result = self::getResult($cursor);

        RedisCache::set($hashKey, $result, 30);

        return $result;
    }

    private static function applyPage(&$cursor, $page, $limit)
    {
        if ($page > 0) {
            $cursor->skip($page * $limit);
        }
    }

    private static function applyLimit(&$cursor, $parameters, $limit)
    {
        if (!isset($parameters['nolimit'])) {
            $cursor->limit($limit);
        }
    }

    private static function getResult(&$cursor)
    {
        $result = array();
        foreach ($cursor as $row) {
            $result[] = $row;
        }
        return $result;
    }

    public static function buildQuery(&$parameters, $useElemMatch = true)
    {
        global $redis;

        $elemMatch = [];
        $and = [];

        foreach ($parameters as $key => $value) {
            if (is_array($value)) {
                $filter = ['$in' => $value];
            } else {
                $filter = $value;
            }
            switch ($key) {
                case 'week':
                case 'xml':
                case 'cacheTime':
                case 'exHours':
                case 'apionly':
                case 'no-attackers':
                case 'no-items':
                case 'api':
                case 'apionly':
                case 'api-only':
                case 'api_only':
                case 'kill':
                case 'page':
                case 'limit':
                case 'combined':
                case 'mixed':
                case 'asc':
                case 'desc':
                case 'orderDirection':
                    break;
                case 'year':
                    /*if (!isset($parameters['month'])) {
                        $start = strtotime("$value-01-01");
                        $end = strtotime("$value-12-31 23:59:59");
                        $and[] = ['dttm' => ['$gte' => new MongoDate($start)]];
                        $and[] = ['dttm' => ['$lt' => new MongoDate($end)]];
                    }*/
                    break;
                case 'month':
                    $year = isset($parameters['year']) ? $parameters['year'] : date('Y');
                    $month = $value;
                    if (strlen($month) < 2) $month = "0$month";
                    $first = self::getFirstKillID($year, $month);
                    $last = self::getFirstKillID(($month == 12 ? $year + 1 : $year), ($month == 12 ? 1 : $month + 1));
                    $and[] = ['killID' => ['$gte' => (int) $first]];
                    if ("$year$month" != date('Ym')) $and[] = ['killID' => ['$lt' => (int) $last]];
                    break;
                case 'date':
                    $time = strtotime($value);
                    $time = $time - ($time % 86400);
                    $and[] = ['dttm' => ['$gte' => new MongoDate($time)]];
                    $and[] = ['dttm' => ['$lt' => new MongoDate($time + 86400)]];
                    break;
                case 'relatedTime':
                    $time = strtotime($value);
                    $exHours = isset($parameters['exHours']) ? (int) $parameters['exHours'] : 1;
                    $and[] = ['dttm' => ['$gte' => new MongoDate($time - (3600 * $exHours))]];
                    $and[] = ['dttm' => ['$lte' => new MongoDate($time + (3600 * $exHours))]];
                    break;
                case 'pastSeconds':
                    $value = min($value, (90 * 86400));
                    $value = max(0, $value);
                    if ($value % 3600 != 0) throw new Exception("pastSeconds must be in increments of 3600 - use redisq if you want up to the second killmails https://github.com/zKillboard/RedisQ");
                    $and[] = ['dttm' => ['$gte' => new MongoDate(time() - $value)]];
                    break;
                case 'beforeKillID':
                    $and[] = ['killID' => ['$lt' => ((int) $value)]];
                    break;
                case 'afterKillID':
                    $and[] = ['killID' => ['$gt' => ((int) $value)]];
                    break;
                case 'war':
                case 'warID':
                    $and[] = ['warID' => (int) $filter];
                    break;
                case 'killID':
                    $and[] = ['killID' => (int) $filter];
                    break;
                case 'iskValue':
                    $and[] = ['zkb.totalValue' => ['$gte' => ((double) $value)]];
                    break;
                case 'victim':
                case 'reset':
                    if (sizeof($elemMatch)) {
                        $and[] = ['involved' => ['$elemMatch' => $elemMatch]];
                        $elemMatch = [];
                    }
                    break;
                case 'kills':
                    if ($value == false) {
                        break;
                    }
                    if ($useElemMatch) {
                        $elemMatch['isVictim'] = false;
                    } else {
                        $and[] = ['involved.isVictim' => false];
                    }
                    break;
                case 'losses':
                    if ($useElemMatch) {
                        $elemMatch['isVictim'] = true;
                    } else {
                        $and[] = ['involved.isVictim' => true];
                    }
                    break;
                case 'finalblow-only':
                    if ($useElemMatch) {
                        $elemMatch['finalBlow'] = true;
                    } else {
                        $and[] = ['involved.finalBlow' => true];
                    }
                    break;
                case 'locationID':
                    $and[] = ['locationID' => $filter];
                    break;
                case 'categoryID':
                    $and[] = ['categoryID' => $filter];
                    break;
                case 'allianceID':
                case 'characterID':
                case 'corporationID':
                case 'groupID':
                case 'factionID':
                case 'shipTypeID':
                case 'isVictim':
                    if ($useElemMatch) {
                        $elemMatch[$key] = $filter;
                    } else {
                        $and[] = ['involved.'.$key => $filter];
                    }
                    break;
                case 'regionID':
                case 'solarSystemID':
                    $and[] = ['system.'.$key => $filter];
                    break;
                case 'awox':
                    $and[] = ['awox' => true];
                    break;
                case 'solo':
                    $and[] = ['solo' => true];
                    break;
                case 'npc':
                    $and[] = ['npc' => (bool) $value];
                    break;
                case 'startTime':
                    $time = strtotime($value);
                    $and[] = ['dttm' => ['$gte' => new MongoDate($time)]];
                    break;
                case 'endTime':
                    $time = strtotime($value);
                    $and[] = ['dttm' => ['$lte' => new MongoDate($time)]];
                    break;
                case 'orderBy':
                    // handled by sort, can be ignored
                    break;
                case 'ganked':
                    $and[] = ['ganked' => true];
                    break;
                case 'w-space':
                    $and[] = ['system.regionID' => ['$gte' => 11000001]];
                    $and[] = ['system.regionID' => ['$lte' => 11000033]];
                    break;
                case 'highsec':
                    $and[] = ['system.security' => ['$gte' => 0.45]];
                    break;
                case 'lowsec':
                    $and[] = ['system.security' => ['$lt' => 0.45]];
                    $and[] = ['system.security' => ['$gte' => 0.05]];
                    break;
                case 'nullsec':
                    $and[] = ['system.security' => ['$lt' => 0.05]];
                    $and[] = ['system.regionID' => ['$lt' => 11000001]]; 
                    break;
                case 'afterSequence':
                    $and[] = ['sequence' => ['$gt' => $value]];
                    break;
                case 'beforeSequence':
                    $and[] = ['sequence' => ['$lt' => $value]];
                    break;
            }
        }

        // Add elemMatch to the $and statement
        if (sizeof($elemMatch) > 0) {
            $and[] = ['involved' => ['$elemMatch' => $elemMatch]];
        }

        // Prep the query, not using $and if it isn't needed
        $query = array();
        if (sizeof($and) == 1) {
            $query = $and[0];
        } elseif (sizeof($and) > 1) {
            $query = ['$and' => $and];
        }

        return $query;
    }

    public static function getFirstKillID($year, $month)
    {
        global $redis;

        if (strlen("$month") < 2) $month = "0$month";
        $key = "zkb:day:{$year}{$month}01";
        $set = $redis->hgetall($key);
        reset($set);
        $killID = (int) key($set);
        return $killID;
    }
}
