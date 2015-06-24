<?php

class MongoFilter
{
    public static function getKills($parameters)
    {
        global $mdb;

        $limit = isset($parameters['limit']) ? (int) $parameters['limit']  : 50;
        if ($limit > 200) {
            $limit = 200;
        }
        if ($limit < 1) {
            $limit = 1;
        }
        $page = isset($parameters['page']) ? ($parameters['page'] == 0 ? 0 : $parameters['page'] - 1) : 0;

        $hashKey = 'MongoFilter::getKills:'.serialize($parameters).":$limit:$page";
        $result = Cache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        // Build the query parameters
        $query = self::buildQuery($parameters);
        if ($query === null) {
            return;
        }

        // Start the query
        $killmails = $mdb->getCollection('killmails');
        $cursor = $killmails->find($query, ['_id' => 0, 'killID' => 1])->timeout(-1);

        // Apply the sort order
        $sortDirection = isset($parameters['orderDirection']) ? ($parameters['orderDirection'] == 'asc' ? 1 : -1)  : -1;
        $sortKey = isset($parameters['orderBy']) ? $parameters['orderBy'] : 'killID';
        $cursor->sort([$sortKey => $sortDirection]);

        // Apply the limit
        $limit = isset($parameters['limit']) ? (int) $parameters['limit']  : 50;
        if ($limit > 200) {
            $limit = 200;
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($page > 0) {
            $cursor->skip($page * $limit);
        }
        $cursor->limit($limit);

        $result = array();
        foreach ($cursor as $row) {
            $result[] = $row;
        }

        Cache::set($hashKey, $result, 30);

        return $result;
    }

    public static function buildQuery(&$parameters, $useElemMatch = true)
    {
        global $mdb;

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
                    $start = strtotime("$value-01-01");
                    $end = strtotime("$value-12-31");
                    $startKillID = self::getKillIDFromTime($start, 1);
                    $endKillID = self::getKillIDFromTime($end, -1);
                    $and[] = ['killID' => ['$gte' => $startKillID]];
                    if ($endKillID > 0) {
                        $and[] = ['killID' => ['$lte' => $endKillID]];
                    }
                    break;
                case 'month':
                    $year = $parameters['year'];
                    $start = strtotime("$year-$value-01");
                    $nextMonth = $value + 1;
                    $end = strtotime("$year-$nextMonth-01");
                    $startKillID = self::getKillIDFromTime($start, 1);
                    $endKillID = self::getKillIDFromTime($end, -1);
                    $and[] = ['killID' => ['$gte' => $startKillID]];
                    if ($endKillID > 0) {
                        $and[] = ['killID' => ['$lt' => $endKillID]];
                    }
                    break;
                case 'relatedTime':
                    $time = strtotime($value);
                    $exHours = isset($parameters['exHours']) ? (int) $parameters['exHours'] : 1;
                    $startKillID = self::getKillIDFromTime($time - ($exHours * 3600), 1);
                    $endKillID = self::getKillIDFromTime($time + ($exHours * 3600), -1);
                    $and[] = ['killID' => ['$gte' => $startKillID]];
                    $and[] = ['killID' => ['$lte' => $endKillID]];
                    break;
                case 'pastSeconds':
                    $prevKillID = self::getKillIDFromTime(time() - $value);
                    $and[] = ['killID' => ['$gte' => $prevKillID]];
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
                case 'startTime':
                    $killID = self::getKillIDFromDttm($value, 1);
                    $and[] = ['killID' => ['$gte' => $killID]];
                    $parameters['orderDirection'] = 'asc';
                    break;
                case 'endTime':
                    $killID = self::getKillIDFromDttm($value, 1);
                    if ($killID !== null) {
                        $and[] = ['killID' => ['$lte' => $killID]];
                    }
                    break;
                case 'orderBy':
                    // handled by sort, can be ignored
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

    public static function getKillIDFromTime($time, $sort = 1)
    {
        global $mdb;

        $start = null;
        $end = null;
        if ($sort == 1) {
            $start = $time;
            $end = $time + 9600;
        } else {
            $start = $time - 9600;
            $end = $time;
        }

        $begin = new MongoDate($start);
        $final = new MongoDate($end);

        $killmails = $mdb->getCollection('killmails');
        $query = ['$and' => [['dttm' => ['$gte' => $begin]], ['dttm' => ['$lte' => $final]]]];
        $result = $killmails->find($query, ['_id' => 0, 'killID' => true])->sort(['killID' => $sort])->limit(1);
        foreach ($result as $row) {
            return $row['killID'];
        }

        return 0;
    }

    public static function getKillIDFromDttm($dttm, $sort)
    {
        global $mdb;

        if (is_string($dttm)) {
            $time = strtotime($dttm);
        } else {
            $time = $dttm;
        }
        if ($time > time()) {
            return;
        }

        $start = null;
        $end = null;
        if ($sort == 1) {
            $start = $time;
            $end = $time + 9600;
        } else {
            $start = $time - 9600;
            $end = $time;
        }

        $begin = new MongoDate($start);
        $final = new MongoDate($end);

        $killmails = $mdb->getCollection('killmails');
        $query = ['$and' => [['dttm' => ['$gte' => $begin]], ['dttm' => ['$lte' => $final]]]];
        $result = $killmails->find($query, ['_id' => 0, 'killID' => true])->sort(['killID' => $sort])->limit(1);
        foreach ($result as $row) {
            return $row['killID'];
        }

        return 0;
    }
}
