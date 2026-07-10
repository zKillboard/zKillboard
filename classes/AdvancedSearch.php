<?php

use cvweiss\redistools\RedisCache;

class AdvancedSearch 
{
    const MAX_ITEM_HISTORY_KILLIDS = 25000;
    const LOG_CONTEXT_MAX_LENGTH = 12000;

    static public $labels = [
        'location' => [
            "loc:highsec" => "HighSec", 
            "loc:lowsec" => "LowSec", 
            "loc:nullsec" => "NullSec", 
            "loc:w-space" => "W-Space", 
            "loc:abyssal" => "Abyssal",
        ],
        'count' => [
            "solo" => "Solo",
            "#:1" => "Just 1",
            "#:2+" => "2+",
            "#:5+" => "5+", 
            "#:10+" => "10+", 
            "#:25+" => "25+", 
            "#:50+" => "50+", 
            "#:100+" => "100+", 
            "#:1000+" => "1000+"
        ],
        'primetime' => [
            "tz:au" => "Aus / China", 
            "tz:eu" => "Europe", 
            "tz:ru" => "Russian", 
            "tz:use" => "USA East", 
            "tz:usw" => "USA West",
        ],
        'flags' => [
            "awox" => "Awox", 
            "ganked" => "HighSec Gank", 
            "npc" => "NPC", 
            "pvp" => "PVP", 
            "padding" => "Padding"
        ],
        'isk' => [
            "isk:1b+" => "1b+", 
            "isk:5b+" => "5b+", 
            "isk:10b+" => "10b+", 
            "isk:100b+" => "100b+",
            "isk:1t+" => "1t+"
        ],
        'custom' => [
            'cat:22' => "Anchored", 
            'atShip' => "AT Ships", 
            'capital' => "Capitals", 
            'cat:18' => "Drone", 
            'cat:87' => "Fighter",
            'cat:46' => "PI", 
            'cat:23' => "POS",  
            'cat:6' => "Ship", 
            'cat:40' => "Sov",  
            'cat:65' => "Structure"
            /*'cat:0' => "Cat 0", */
            /*'cat:11' => "Structure Light Fighter", */
            /*'cat:350001' => "Dust 514", */
        ]
    ];

    public static function buildQuery($queryParams, $queries, $key, $isVictim = null, $joinType = 'and', $useElemMatch = true)
    {
        $query = self::buildFromArray($key, $isVictim, $joinType, $useElemMatch, $queryParams);
        if ($query != null && sizeof($query) > 0) $queries[] = $query;
        return $queries;
    }

    public static function runQueuedQuery($job)
    {
        global $mdb, $advancedSearchMaxTimeSeconds;

        $maxTimeMS = (isset($advancedSearchMaxTimeSeconds) ? max(1, (int) $advancedSearchMaxTimeSeconds) : 60) * 1000;

        if (isset($job['queryParams']['items'])) {
            if (isset($job['query']['$and']) && is_array($job['query']['$and'])) $queries = $job['query']['$and'];
            else $queries = empty($job['query']) ? [] : [$job['query']];
            $queries = self::buildItemHistoryQuery($job['queryParams'], $queries, "items", $job['itemJoin'], $maxTimeMS);
            if (sizeof($queries) == 0) $job['query'] = [];
            else if (sizeof($queries) == 1) $job['query'] = $queries[0];
            else $job['query'] = ['$and' => $queries];
        }

        if ($job['queryType'] == 'kills') {
            try {
                $result = [];
                foreach ($job['coll'] as $col) {
                    $cursor = $mdb->getCollection($col)->find($job['query'], ['sort' => $job['sort'], 'limit' => 100, 'skip' => 100 * $job['page'], 'maxTimeMS' => $maxTimeMS]);
                    $result = is_array($cursor) ? $cursor : iterator_to_array($cursor);
                    if (sizeof($result) >= 100) break;
                }
                $kills = [];
                foreach ($result as $row) $kills[] = $row['killID'];
                return ['kills' => $kills];
            } catch (Exception $ex) {
                if ($ex->getCode() == 50) self::logTimeout('AdvancedSearch::getKills', [
                    'collections' => $job['coll'],
                    'queryType' => $job['queryType'],
                    'query' => $job['query'],
                    'sort' => $job['sort'],
                    'page' => $job['page'],
                    'requestParams' => $job['queryParams'] ?? []
                ], $ex);
                else Util::zout(print_r($ex, true));
                return ['kills' => []];
            }
        }
        if ($job['queryType'] == 'count') {
            $result = self::getSums($job['groupType'] . 'ID', $job['query'], $job['victimsOnly'], false, true, $job['aggregateCollection'], $maxTimeMS);
            unset($result['_id']);
            return $result;
        }
        if ($job['queryType'] == 'groups') {
            if (!in_array($job['groupType'], $job['types'], true)) return [];
            return self::getTop($job['groupType'] . 'ID', $job['query'], $job['victimsOnly'], $job['filter'], true, $job['sortKey'], $job['sortBy'], $job['aggregateCollection'], $maxTimeMS);
        }
        if ($job['queryType'] == 'labels') return self::getLabels($job['query'], $job['victimsOnly']);
        if ($job['queryType'] == 'distincts') return self::getDistincts($job['query'], $job['filter'], $job['victimsOnly'], $job['aggregateCollection'], $maxTimeMS);
        return [];
    }

    public static function buildItemHistoryQuery($queryParams, $queries, $key = 'items', $joinType = 'and', $maxTimeMS = 5000)
    {
        global $mdb;

        if (!isset($queryParams[$key]) || !is_array($queryParams[$key])) return $queries;

        $typeIDs = [];
        foreach ($queryParams[$key] as $row) {
            if (!isset($row['id'])) continue;
            $typeID = (int) $row['id'];
            if ($typeID > 0) $typeIDs[$typeID] = $typeID;
        }
        $typeIDs = array_values($typeIDs);
        if (sizeof($typeIDs) == 0) return $queries;

        $candidateKillIDs = self::getCandidateKillIDs($queries, $maxTimeMS);
        if (sizeof($candidateKillIDs) == 0) {
            $queries[] = ['killID' => -1];
            return $queries;
        }
        $itemmails = $mdb->getCollection('itemmails');
        $itemMatch = ['typeID' => ['$in' => $typeIDs], 'killID' => ['$in' => $candidateKillIDs]];

        $killIDs = [];
        if ($joinType == 'or' || $joinType == '-or' || sizeof($typeIDs) == 1) {
            $options = ['projection' => ['killID' => 1, '_id' => 0]];
            if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
            $cursor = $itemmails->find($itemMatch, $options);
            foreach ($cursor as $row) {
                $killID = (int) @$row['killID'];
                if ($killID > 0) $killIDs[$killID] = $killID;
            }
        } else {
            $options = ['allowDiskUse' => true];
            if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
            $cursor = $itemmails->aggregate([
                ['$match' => $itemMatch],
                ['$group' => ['_id' => '$killID', 'typeIDs' => ['$addToSet' => '$typeID']]],
                ['$project' => ['killID' => '$_id', 'matches' => ['$size' => '$typeIDs'], '_id' => 0]],
                ['$match' => ['matches' => sizeof($typeIDs)]]
            ], $options);
            foreach ($cursor as $row) {
                $killID = (int) @$row['killID'];
                if ($killID > 0) $killIDs[$killID] = $killID;
            }
        }

        $killIDs = array_values($killIDs);
        $queries[] = ['killID' => sizeof($killIDs) ? ['$in' => $killIDs] : -1];
        return $queries;
    }

    private static function getCandidateKillIDs($queries, $maxTimeMS = 5000)
    {
        global $mdb;

        $baseQuery = self::buildMongoQuery($queries);

        $killIDs = [];
        $options = [
            'projection' => ['killID' => 1, '_id' => 0],
            'sort' => ['killID' => -1],
            'limit' => self::MAX_ITEM_HISTORY_KILLIDS
        ];
        if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
        $cursor = $mdb->getCollection('killmails')->find($baseQuery, $options);

        foreach ($cursor as $row) {
            $killID = (int) @$row['killID'];
            if ($killID > 0) $killIDs[$killID] = $killID;
        }

        return array_values($killIDs);
    }

    private static function buildMongoQuery($queries)
    {
        $query = [];
        foreach ($queries as $key => $value) {
            if (is_int($key) && is_array($value)) {
                $query[] = $value;
            }
        }

        if (sizeof($query) == 0) return [];
        if (sizeof($query) == 1) return $query[0];
        return ['$and' => $query];
    }

    public static function getKillIDFilter($queries)
    {
        $filter = [];
        foreach ($queries as $query) {
            if (!isset($query['killID']) || !is_array($query['killID'])) continue;
            foreach (['$gte', '$lte', '$gt', '$lt'] as $operator) {
                if (isset($query['killID'][$operator])) {
                    $filter[$operator] = $query['killID'][$operator];
                }
            }
        }
        return $filter;
    }

    public static function buildFromArray($key, $isVictim = null, $joinType = 'and', $useElemMatch, $queryParams)
    {
        if (!isset($queryParams[$key])) return null;

        $arr = $queryParams[$key];
        $params = [];
        foreach ($arr as $row) {
            $param = [];
            if ($row['type'] == 'systemID') $row['type'] = 'solarSystemID';
            if ($row['type'] == 'shipID') $row['type'] = 'shipTypeID';
            if ($row['type'] == 'typeID') $row['type'] = 'shipTypeID';

            $param[$row['type']] = (int) $row['id'];
            if ($isVictim === false) $param['kills'] = true;
            else if ($isVictim === true) $param['losses'] = true;
            $params[] = MongoFilter::buildQuery($param, $useElemMatch);
        }

        if ($joinType == 'or' || $joinType == '-or') return ['$or' => $params];
        if ($joinType == 'and' || $joinType == "-and") return ['$and' => $params];

        // Last option is 'mergedand' or 'In' on the site, we need to merge everything
        $merged = [];
        foreach ($params as $param) {
            $merged = array_merge_recursive($merged, $param);
        }
        if (isset($merged['involved']['$elemMatch']['isVictim'])) $merged['involved']['$elemMatch']['isVictim'] = $isVictim;
        return $merged;
    }

    public static function getLabelGroup($label)
    {
        foreach (self::$labels as $group => $labels) {
            if (in_array($label, array_keys($labels))) return $group;
        }
        return null;
    }

    public static function parseDate($queryParams, $query, $which)
    {
        $val = (string) ($queryParams['epoch'][$which] ?? '');
        if ($val == "") return $query;

        $time = strtotime($val);
        if ($time > time()) {
			// no point in adding a filter for a future date
            return $query;
        }

        $killID = Info::findKillID($time, $which);
        if ($killID != null) {
            $query[] = ['killID' => [($which == 'start' ? '$gte' : '$lte') => $killID]];
            $query['hasDateFilter'] = true;
            $query[$which] = strtotime($val);
        }

        return $query;
    }

    public static function getTop($groupByColumn, $query, $victimsOnly, $filter, $addInfo, $sortKey, $sortBy, $collection = 'killmails', $maxTimeMS = 25000)
    {
        global $mdb, $longQueryMS, $redis;

        $collection = self::getAggregateCollection($collection);
        if ($collection == null) return [];
        $hashKey = "Stats::getTop:r:$collection:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly) . ":$sortKey:$sortBy";
        while ($redis->get("inprogress:$hashKey") == "true") sleep(1);
        try {
            $redis->setex("inprogress:$hashKey", 60, "true");

            $killmails = $mdb->getCollection($collection);

            if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
                $keyField = "system.$groupByColumn";
            } elseif ($groupByColumn != 'locationID') {
                $keyField = "involved.$groupByColumn";
            } else {
                $keyField = $groupByColumn;
            }

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => (empty($query) ? new stdClass() : $query)];
            if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
                $pipeline[] = ['$unwind' => '$involved'];
                if (sizeof($filter)) $pipeline[] = ['$match' => $filter];
            }
            if ($victimsOnly != "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
            $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
            $pipeline[] = ['$match' => [$keyField => ['$ne' => 0]]];

            $groupNeedsKillDedupe = !in_array($groupByColumn, ['solarSystemID', 'regionID', 'locationID'], true);
            if ($groupNeedsKillDedupe) {
                $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$' . $keyField, 'totalValue' => '$zkb.totalValue', 'involved' => '$attackerCount', 'damage' => '$damage_taken']]];
                $groupValueField = '$_id.' . $groupByColumn;
                $totalValueField = '$_id.totalValue';
                $involvedField = '$_id.involved';
                $damageField = '$_id.damage';
            } else {
                $groupValueField = '$' . $keyField;
                $totalValueField = '$zkb.totalValue';
                $involvedField = '$attackerCount';
                $damageField = '$damage_taken';
            }

            if ($sortKey == "damage_taken") {
                $pipeline[] = ['$group' => ['_id' => $groupValueField, 'kills' => ['$sum' => $damageField]]];
            } else if ($sortKey == "attackerCount") {
                $pipeline[] = ['$group' => ['_id' => $groupValueField, 'kills' => ['$avg' => $involvedField]]];
            } else if ($sortKey == "zkb.totalValue") {
                $pipeline[] = ['$group' => ['_id' => $groupValueField, 'kills' => ['$sum' => $totalValueField]]];
            } else {
                $pipeline[] = ['$group' => ['_id' => $groupValueField, 'kills' => ['$sum' => 1]]];
            }
            $pipeline[] = ['$sort' => ['kills' => $sortBy]];
            $pipeline[] = ['$limit' => 550];
            $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

            $options = ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true];
            if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
            $rr = $killmails->aggregate($pipeline, $options);
            $result = iterator_to_array($rr);

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $hashKey $uri");
            }

            $result = Util::removeDQed($result, $groupByColumn, 500);

            if ($addInfo) Info::addInfo($result);

            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() == 50) {
                self::logTimeout('AdvancedSearch::getTop', [
                    'collection' => $collection,
                    'groupByColumn' => $groupByColumn,
                    'victimsOnly' => $victimsOnly,
                    'filter' => $filter,
                    'sortKey' => $sortKey,
                    'sortBy' => $sortBy,
                    'query' => $query,
                    'pipeline' => $pipeline ?? [],
                    'hashKey' => $hashKey
                ], $ex);
            } else {
                Util::zout(print_r($ex, true));
            }
            RedisCache::set($hashKey, [], 900);
            return [];
        } finally {
            $redis->del("inprogress:$hashKey");
        }
    }

    public static function getSums($groupByColumn, $query, $victimsOnly, $cacheOverride = false, $addInfo = true, $collection = 'killmails', $maxTimeMS = 25000)
    {
        global $mdb, $longQueryMS;

        $collection = self::getAggregateCollection($collection);
        if ($collection == null) return self::getEmptySums();
        $hashKey = "Stats::getSums:q:$collection:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
        try {
            $result = RedisCache::get($hashKey);
            if ($cacheOverride == false && $result != null) {
                return $result;
            }

            $killmails = $mdb->getCollection($collection);

            if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
                $keyField = "system.$groupByColumn";
            } elseif ($groupByColumn != 'locationID') {
                $keyField = "involved.$groupByColumn";
            } else {
                $keyField = $groupByColumn;
            }

            $id = $type = null;
            if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
                $type = "involved." . $groupByColumn;
            }

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => (empty($query) ? new stdClass() : $query)];
            if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
            $pipeline[] = ['$group' => [
                '_id' => 0, 
                'isk' => ['$sum' => '$zkb.totalValue'], 
                'droppable' => ['$sum' => '$zkb.totalDroppableValue'], 
                'fitted' => ['$sum' => '$zkb.fittedValue'], 
                'dropped' => ['$sum' => '$zkb.droppedValue'], 
                'destroyed' => ['$sum' => '$zkb.destroyedValue'], 
                'kills' => ['$sum' => 1]
                ]
            ];

            $options = ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true];
            if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
            $rr = $killmails->aggregate($pipeline, $options);
            $resultArray = iterator_to_array($rr);
            $result = !empty($resultArray) ? $resultArray[0] : self::getEmptySums();

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $hashKey $uri");
            }

            RedisCache::set($hashKey, $result, 900);

            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() == 50) {
                self::logTimeout('AdvancedSearch::getSums', [
                    'collection' => $collection,
                    'groupByColumn' => $groupByColumn,
                    'victimsOnly' => $victimsOnly,
                    'query' => $query,
                    'pipeline' => $pipeline ?? [],
                    'hashKey' => $hashKey
                ], $ex);
            } else {
                Util::zout(print_r($ex, true));
            }
            RedisCache::set($hashKey, [], 900);
            return self::getEmptySums();
        }
    }

    public static function getLabels($query, $victimsOnly)
    {
        return [];
        global $mdb, $longQueryMS;
        $pipeline = [];

        try {
            $killmails = $mdb->getCollection('killmails');

            $timer = new Timer();
            $pipeline[] = ['$match' => (empty($query) ? new stdClass() : $query)];
            if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];

            $pipeline[] = ['$unwind' => '$labels'];
            $pipeline[] = ['$project' => ['split' => ['$split' => ['$labels', ':']]]];
            $pipeline[] = [
                '$project' => [
                    'left' => [
                        '$switch' => [
                            'branches' => [
                                [
                                    'case' => [
                                        '$eq' => [
                                            ['$arrayElemAt' => ['$split', 0]],
                                            'solo'
                                        ]
                                    ],
                                    'then' => 'involved'
                                ],
                                [
                                    'case' => [
                                        '$eq' => [
                                            ['$size' => '$split'],
                                            1
                                        ]
                                    ],
                                    'then' => 'other'
                                ]
                            ],
                            'default' => [
                                '$cond' => [
                                    [
                                        '$eq' => [
                                            ['$arrayElemAt' => ['$split', 0]],
                                            '#'
                                        ]
                                    ],
                                    'involved',
                                    ['$arrayElemAt' => ['$split', 0]]
                                ]
                            ]
                        ]
                    ],
                    'right' => [
                        '$switch' => [
                            'branches' => [
                                [
                                    'case' => [
                                        '$eq' => [
                                            ['$arrayElemAt' => ['$split', 0]],
                                            'solo'
                                        ]
                                    ],
                                    'then' => 'solo'
                                ],
                                [
                                    'case' => [
                                        '$eq' => [
                                            ['$size' => '$split'],
                                            1
                                        ]
                                    ],
                                    'then' => ['$arrayElemAt' => ['$split', 0]]
                                ]
                            ],
                            'default' => ['$arrayElemAt' => ['$split', 1]]
                        ]
                    ]
                ]
            ];

            $pipeline[] = [
                '$group' => [
                    '_id' => [
                        'left' => '$left',
                        'right' => '$right'
                    ],
                    'count' => ['$sum' => 1]
                ]
            ];

            $pipeline[] = [
                '$sort' => [
                    '_id.left' => 1,
                    'count' => -1,
                    '_id.right' => 1
                ]
            ];

            $pipeline[] = [
                '$group' => [
                    '_id' => '$_id.left',
                    'rights' => [
                        '$push' => [
                            'right' => '$_id.right',
                            'count' => '$count'
                        ]
                    ]
                ]
            ];

            $pipeline[] = [
                '$sort' => [
                    '_id' => 1
                ]
            ];

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
            $result = iterator_to_array($rr);

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $uri");
            }
            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() != 50) Util::zout(print_r($ex, true) . "\n" . print_r($pipeline, true));
            else self::logTimeout('AdvancedSearch::getLabels', [
                'victimsOnly' => $victimsOnly,
                'query' => $query,
                'pipeline' => $pipeline
            ], $ex);
            return [];
        }
    }

    public static function getDistincts($query, $filter, $victimsOnly, $collection = 'killmails', $maxTimeMS = 25000)
    {
        global $mdb, $longQueryMS;

        $collection = self::getAggregateCollection($collection);
        if ($collection == null) return [];
        $hashKey = "Stats::getDistincts:$collection:" . serialize($query) . ":" . serialize($victimsOnly);
        try {
            $killmails = $mdb->getCollection($collection);

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => (empty($query) ? new stdClass() : $query)];
            if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];

            $pipeline[] = [
                '$unwind' => '$involved'
            ];
            if (sizeof($filter)) $pipeline[] = ['$match' => $filter];

            $pipeline[] = [
                '$group' => [
                    '_id' => null,
                    'characterIDs'    => ['$addToSet' => '$involved.characterID'],
                    'corporationIDs'  => ['$addToSet' => '$involved.corporationID'],
                    'allianceIDs'     => ['$addToSet' => '$involved.allianceID'],
                    'factionIDs'      => ['$addToSet' => '$involved.factionID'],
                    'shipTypeIDs'     => ['$addToSet' => '$involved.shipTypeID'],
                    'groupIDs'        => ['$addToSet' => '$involved.groupID'],
                    'solarSystemIDs'  => ['$addToSet' => '$system.solarSystemID'],
                    'constellationIDs' => ['$addToSet' => '$system.constellationID'],
                    'regionIDs'       => ['$addToSet' => '$system.regionID']
                ]
            ];

            $pipeline[] = [
                '$project' => [
                    '_id' => 0,
                    'characterIDs' => ['$size' => ['$filter' => ['input' => '$characterIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'corporationIDs' => ['$size' => ['$filter' => ['input' => '$corporationIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'allianceIDs' => ['$size' => ['$filter' => ['input' => '$allianceIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'factionIDs' => ['$size' => ['$filter' => ['input' => '$factionIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'shipTypeIDs' => ['$size' => ['$filter' => ['input' => '$shipTypeIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'groupIDs' => ['$size' => ['$filter' => ['input' => '$groupIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'solarSystemIDs' => ['$size' => ['$filter' => ['input' => '$solarSystemIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'constellationIDs' => ['$size' => ['$filter' => ['input' => '$constellationIDs', 'cond' => ['$ne' => ['$$this', null]]]]],
                    'regionIDs' => ['$size' => ['$filter' => ['input' => '$regionIDs', 'cond' => ['$ne' => ['$$this', null]]]]]
                ]
            ];

            $options = ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true];
            if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;
            $rr = $killmails->aggregate($pipeline, $options);
            $resultArray = iterator_to_array($rr);
            $result = !empty($resultArray) ? $resultArray[0] : [];

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $uri");
            }

            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() != 50) Util::zout(print_r($ex, true));
            else self::logTimeout('AdvancedSearch::getDistincts', [
                'collection' => $collection,
                'victimsOnly' => $victimsOnly,
                'filter' => $filter,
                'query' => $query,
                'pipeline' => $pipeline ?? [],
                'hashKey' => $hashKey
            ], $ex);
            return [];
        }
    }

    public static function logTimeout($operation, $context = [], $ex = null)
    {
        global $uri, $logAsearchAlltimeTimeouts;

        $sourceUri = isset($context['uri']) ? $context['uri'] : $uri;
        $params = [];
        if ($sourceUri) {
            $parts = parse_url($sourceUri);
            if (isset($parts['query'])) parse_str($parts['query'], $params);
        }
        if (isset($context['requestParams']) && is_array($context['requestParams'])) {
            $params = array_replace_recursive($context['requestParams'], $params);
        }
        if (@$logAsearchAlltimeTimeouts !== true && ((string) @$params['epochbtn'] == 'alltime' || (isset($params['buttons']) && is_array($params['buttons']) && in_array('alltime', $params['buttons'], true)))) return;

        $sort = isset($params['sort']) && is_array($params['sort']) ? $params['sort'] : [];
        $radios = isset($params['radios']) && is_array($params['radios']) ? $params['radios'] : [];
        if (isset($radios['sort']) && is_array($radios['sort'])) $sort = array_replace($sort, $radios['sort']);
        $sortBy = isset($sort['sortBy']) ? $sort['sortBy'] : @$context['sortKey'];
        $sortDir = isset($sort['sortDir']) ? $sort['sortDir'] : @$context['sortBy'];
        if ($sortDir === 1 || $sortDir === '1') $sortDir = 'asc';
        if ($sortDir === -1 || $sortDir === '-1') $sortDir = 'desc';
        $sortText = trim("$sortBy $sortDir");

        $queryType = isset($context['queryType']) ? $context['queryType'] : @$params['queryType'];
        $groupType = isset($context['groupType']) ? $context['groupType'] : @$params['groupType'];
        $page = isset($context['page']) ? $context['page'] : @$radios['page'];
        $page = (int) $page;
        $collection = @$context['collection'] ?: @$context['aggregateCollection'];
        $groupBy = @$context['groupByColumn'];
        $timeSpan = self::summarizeTimeoutTimeSpan($params);
        $epoch = self::summarizeTimeoutEpoch(@$params['epoch']);
        $query = self::summarizeTimeoutCriteria(@$context['query']);
        $filter = self::summarizeTimeoutCriteria(@$context['filter']);
        $execution = implode(' ', array_filter([
            $collection ? "collection $collection" : null,
            $groupBy ? "groupBy $groupBy" : null
        ]));

        $parts = [
            $operation . ': ' . ($ex ? self::shortTimeoutReason($ex->getMessage()) : 'timed out waiting for in-progress request'),
            "request " . trim("$queryType/$groupType"),
            $page > 1 ? "page $page" : null,
            $sortText && $sortText !== 'date desc' ? "sort $sortText" : null,
            $timeSpan ? "timespan $timeSpan" : null,
            $epoch ? "epoch $epoch" : null,
            isset($params['includeAssociates']) && $params['includeAssociates'] !== 'true' ? "includeAssociates " . $params['includeAssociates'] : null,
            $execution,
            @$context['victimsOnly'] && @$context['victimsOnly'] !== 'null' ? "victimsOnly " . @$context['victimsOnly'] : null,
            $query ? "selected $query" : null,
            $filter ? "filter $filter" : null
        ];

        $encoded = implode('; ', array_values(array_filter($parts)));

        if (strlen($encoded) > self::LOG_CONTEXT_MAX_LENGTH) {
            $encoded = substr($encoded, 0, self::LOG_CONTEXT_MAX_LENGTH) . '... [truncated]';
        }

        Util::zout("Advanced search query timeout: " . $encoded);
    }

    private static function summarizeTimeoutTimeSpan($params)
    {
        if (!is_array($params)) return null;

        $span = trim((string) @$params['epochbtn']);
        if ($span == '' && isset($params['buttons']) && is_array($params['buttons'])) {
            foreach ($params['buttons'] as $button) {
                $button = trim((string) $button);
                if (in_array($button, ['week', 'recent', 'alltime', 'prior month', 'current month', 'custom'], true)) {
                    $span = $button;
                    break;
                }
            }
        }
        if ($span == '') return null;

        $labels = [
            'week' => 'Last 7 Days',
            'recent' => 'Last 90 Days',
            'alltime' => 'Alltime',
            'prior month' => 'Prior Month',
            'current month' => 'Current Month',
            'custom' => 'Custom Date Range'
        ];
        $label = isset($labels[$span]) ? $labels[$span] : $span;

        if (isset($params['buttons']) && is_array($params['buttons']) && in_array('rolling', $params['buttons'], true)) {
            $label .= ' rolling';
        }

        return $label == $span ? $label : "$label ($span)";
    }

    private static function summarizeTimeoutEpoch($epoch)
    {
        if (!is_array($epoch)) return null;
        $start = trim((string) @$epoch['start']);
        $end = trim((string) @$epoch['end']);
        if ($start == '' && $end == '') return null;
        if ($start == '') return "before $end";
        if ($end == '') return "after $start";
        return "$start to $end";
    }

    private static function summarizeTimeoutCriteria($criteria)
    {
        if (!is_array($criteria) || empty($criteria)) return null;

        if (isset($criteria['$and']) && is_array($criteria['$and'])) {
            $parts = [];
            foreach ($criteria['$and'] as $child) {
                $summary = self::summarizeTimeoutCriteria($child);
                if ($summary) $parts[] = $summary;
            }
            return implode('; ', self::combineTimeoutCriteriaParts($parts));
        }

        if (isset($criteria['$or']) && is_array($criteria['$or'])) {
            return self::summarizeTimeoutOr($criteria['$or']);
        }

        $parts = [];
        foreach ($criteria as $field => $value) {
            if (is_array($value) && isset($value['$elemMatch']) && is_array($value['$elemMatch'])) {
                $parts[] = "$field has " . self::summarizeTimeoutCriteria($value['$elemMatch']);
            } elseif (is_array($value)) {
                foreach ($value as $operator => $operatorValue) {
                    $parts[] = "$field " . self::timeoutOperator($operator) . " " . self::timeoutValue($operatorValue);
                }
            } else {
                $parts[] = "$field = " . self::timeoutValue($value);
            }
        }

        return implode('; ', $parts);
    }

    private static function combineTimeoutCriteriaParts($parts)
    {
        $labels = [];
        $combined = [];
        foreach ($parts as $part) {
            if (preg_match('/^labels in \\[(.*)\\]$/', $part, $matches)) {
                foreach (explode(', ', $matches[1]) as $label) $labels[] = $label;
            } else {
                $combined[] = $part;
            }
        }

        if (!empty($labels)) array_unshift($combined, 'labels in [' . implode(', ', $labels) . ']');
        return $combined;
    }

    private static function summarizeTimeoutOr($criteria)
    {
        $field = null;
        $values = [];
        foreach ($criteria as $child) {
            if (!is_array($child) || sizeof($child) != 1) return '$or with ' . sizeof($criteria) . ' branches';
            $childField = key($child);
            if ($field === null) $field = $childField;
            if ($field !== $childField || is_array($child[$childField])) return '$or with ' . sizeof($criteria) . ' branches';
            $values[] = $child[$childField];
        }

        return "$field in " . sizeof($values) . " values " . self::timeoutValueSample($values);
    }

    private static function timeoutOperator($operator)
    {
        $operators = [
            '$eq' => '=',
            '$ne' => '!=',
            '$gt' => '>',
            '$gte' => '>=',
            '$lt' => '<',
            '$lte' => '<=',
            '$in' => 'in',
            '$nin' => 'not in'
        ];
        return isset($operators[$operator]) ? $operators[$operator] : $operator;
    }

    private static function timeoutValue($value)
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_array($value)) return self::timeoutValueSample($value);
        return (string) $value;
    }

    private static function timeoutValueSample($values)
    {
        $values = array_values($values);
        $sample = array_slice(array_map(function ($value) {
            return self::timeoutValue($value);
        }, $values), 0, 8);
        if (sizeof($values) > sizeof($sample)) $sample[] = '+' . (sizeof($values) - sizeof($sample)) . ' more';
        return '[' . implode(', ', $sample) . ']';
    }

    private static function shortTimeoutReason($reason)
    {
        $parts = explode(':: caused by ::', $reason);
        return trim(end($parts));
    }

    public static function getSelectedFromBase($base, $buttons)
    {
        foreach ($buttons as $button) {
            if (Util::startsWith($button, $base)) return str_replace($base, '', $button);
        }
        return 'and'; // default
    }

    private static function getAggregateCollection($collection)
    {
        $allowed = ['oneWeek', 'ninetyDays', 'killmails'];
        return in_array($collection, $allowed, true) ? $collection : null;
    }

    private static function getEmptySums()
    {
        return [
            'isk' => 0,
            'droppable' => 0,
            'fitted' => 0,
            'dropped' => 0,
            'destroyed' => 0,
            'kills' => 0
        ];
    }
}
