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

    public static function buildItemHistoryQuery($queryParams, $queries, $key = 'items', $joinType = 'and')
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

        $candidateKillIDs = self::getCandidateKillIDs($queries);
        if (sizeof($candidateKillIDs) == 0) {
            $queries[] = ['killID' => -1];
            return $queries;
        }
        $itemmails = $mdb->getCollection('itemmails');
        $itemMatch = ['typeID' => ['$in' => $typeIDs], 'killID' => ['$in' => $candidateKillIDs]];

        $killIDs = [];
        if ($joinType == 'or' || $joinType == '-or' || sizeof($typeIDs) == 1) {
            $cursor = $itemmails->find(
                $itemMatch,
                [
                    'projection' => ['killID' => 1, '_id' => 0],
                    'maxTimeMS' => 5000
                ]
            );
            foreach ($cursor as $row) {
                $killID = (int) @$row['killID'];
                if ($killID > 0) $killIDs[$killID] = $killID;
            }
        } else {
            $cursor = $itemmails->aggregate([
                ['$match' => $itemMatch],
                ['$group' => ['_id' => '$killID', 'typeIDs' => ['$addToSet' => '$typeID']]],
                ['$project' => ['killID' => '$_id', 'matches' => ['$size' => '$typeIDs'], '_id' => 0]],
                ['$match' => ['matches' => sizeof($typeIDs)]]
            ], ['allowDiskUse' => true, 'maxTimeMS' => 5000]);
            foreach ($cursor as $row) {
                $killID = (int) @$row['killID'];
                if ($killID > 0) $killIDs[$killID] = $killID;
            }
        }

        $killIDs = array_values($killIDs);
        $queries[] = ['killID' => sizeof($killIDs) ? ['$in' => $killIDs] : -1];
        return $queries;
    }

    private static function getCandidateKillIDs($queries)
    {
        global $mdb;

        $baseQuery = self::buildMongoQuery($queries);

        $killIDs = [];
        $cursor = $mdb->getCollection('killmails')->find(
            $baseQuery,
            [
                'projection' => ['killID' => 1, '_id' => 0],
                'sort' => ['killID' => -1],
                'limit' => self::MAX_ITEM_HISTORY_KILLIDS,
                'maxTimeMS' => 5000
            ]
        );

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

    public static function getTop($groupByColumn, $query, $victimsOnly, $filter, $addInfo, $sortKey, $sortBy, $collection = 'killmails')
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

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
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
        } finally {
            $redis->del("inprogress:$hashKey");
        }
    }

    public static function getSums($groupByColumn, $query, $victimsOnly, $cacheOverride = false, $addInfo = true, $collection = 'killmails')
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

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
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

    public static function getDistincts($query, $filter, $victimsOnly, $collection = 'killmails')
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

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
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
        global $uri;

        $sourceUri = isset($context['uri']) ? $context['uri'] : $uri;
        $params = [];
        $path = null;
        if ($sourceUri) {
            $parts = parse_url($sourceUri);
            $path = isset($parts['path']) ? $parts['path'] : null;
            if (isset($parts['query'])) parse_str($parts['query'], $params);
        }

        $sort = isset($params['sort']) && is_array($params['sort']) ? $params['sort'] : [];
        $radios = isset($params['radios']) && is_array($params['radios']) ? $params['radios'] : [];
        if (isset($radios['sort']) && is_array($radios['sort'])) $sort = array_replace($sort, $radios['sort']);
        $sortBy = isset($sort['sortBy']) ? $sort['sortBy'] : @$context['sortKey'];
        $sortDir = isset($sort['sortDir']) ? $sort['sortDir'] : @$context['sortBy'];
        if ($sortDir === 1 || $sortDir === '1') $sortDir = 'asc';
        if ($sortDir === -1 || $sortDir === '-1') $sortDir = 'desc';

        $payload = [
            'operation' => $operation,
            'reason' => $ex ? $ex->getMessage() : 'timed out waiting for in-progress request',
            'path' => $path,
            'queryType' => isset($context['queryType']) ? $context['queryType'] : @$params['queryType'],
            'groupType' => isset($context['groupType']) ? $context['groupType'] : @$params['groupType'],
            'page' => isset($context['page']) ? $context['page'] : @$radios['page'],
            'sort' => trim("$sortBy $sortDir"),
            'epoch' => @$params['epoch'],
            'labels' => isset($params['labels']) && is_array($params['labels']) ? array_values($params['labels']) : null,
            'includeAssociates' => @$params['includeAssociates'],
            'collection' => @$context['collection'] ?: @$context['aggregateCollection'],
            'collections' => @$context['collections'],
            'groupBy' => @$context['groupByColumn'],
            'victimsOnly' => @$context['victimsOnly'],
            'query' => @$context['query'],
            'filter' => @$context['filter'],
            'pipelineStages' => self::getPipelineStageNames(@$context['pipeline'])
        ];

        $encoded = json_encode(self::removeEmptyTimeoutFields($payload), JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = print_r($payload, true);
        }

        if (strlen($encoded) > self::LOG_CONTEXT_MAX_LENGTH) {
            $encoded = substr($encoded, 0, self::LOG_CONTEXT_MAX_LENGTH) . '... [truncated]';
        }

        Util::zout("Advanced search query timeout: " . $encoded);
    }

    private static function getPipelineStageNames($pipeline)
    {
        if (!is_array($pipeline)) return null;
        $stages = [];
        foreach ($pipeline as $stage) {
            if (is_array($stage) && !empty($stage)) $stages[] = key($stage);
        }
        return $stages;
    }

    private static function removeEmptyTimeoutFields($value)
    {
        if (!is_array($value)) return $value;

        $clean = [];
        foreach ($value as $key => $child) {
            $child = self::removeEmptyTimeoutFields($child);
            if ($child === null || $child === '' || $child === []) continue;
            $clean[$key] = $child;
        }
        return $clean;
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
