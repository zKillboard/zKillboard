<?php

use cvweiss\redistools\RedisCache;

class AdvancedSearch 
{
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
            $query[] = ['killID' => 0];
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

    public static function getTop($groupByColumn, $query, $victimsOnly, $filter, $addInfo, $sortKey, $sortBy)
    {
        global $mdb, $longQueryMS, $redis;

        $hashKey = "Stats::getTop:q:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
        while ($redis->get("inprogress:$hashKey") == "true") sleep(1);
        try {
            $redis->setex("inprogress:$hashKey", 60, "true");

            $killmails = $mdb->getCollection('killmails');

            if ($groupByColumn == 'solarSystemID' || $groupByColumn == "constellationID" || $groupByColumn == 'regionID') {
                $keyField = "system.$groupByColumn";
            } elseif ($groupByColumn != 'locationID') {
                $keyField = "involved.$groupByColumn";
            } else {
                $keyField = $groupByColumn;
            }

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => $query];
            if ($groupByColumn != 'solarSystemID' && $groupByColumn != 'regionID' && $groupByColumn != 'locationID') {
                $pipeline[] = ['$unwind' => '$involved'];
                if (sizeof($filter)) $pipeline[] = ['$match' => $filter];
            }
            if ($victimsOnly != "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
            $pipeline[] = ['$match' => [$keyField => ['$ne' => null]]];
            $pipeline[] = ['$match' => [$keyField => ['$ne' => 0]]];
            $pipeline[] = ['$group' => ['_id' => ['killID' => '$killID', $groupByColumn => '$' . $keyField, 'totalValue' => '$zkb.totalValue', 'involved' => '$attackerCount', 'damage' => '$damage_taken']]];

            if ($sortKey == "damage_taken") {
                $pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => '$_id.damage']]];
            } else if ($sortKey == "attackerCount") {
                $pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$avg' => '$_id.involved']]];
            } else if ($sortKey == "zkb.totalValue") {
                $pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => '$_id.totalValue']]];
            } else {
                $pipeline[] = ['$group' => ['_id' => '$_id.' . $groupByColumn, 'kills' => ['$sum' => 1]]];
            }
            $pipeline[] = ['$sort' => ['kills' => $sortBy]];
            $pipeline[] = ['$limit' => 150];
            $pipeline[] = ['$project' => [$groupByColumn => '$_id', 'kills' => 1, '_id' => 0]];

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
            $result = $rr['result'];

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $hashKey $uri");
            }

            $result = Util::removeDQed($result, $groupByColumn, 100);

            if ($addInfo) Info::addInfo($result);

            return $result;
        } catch (Exception $ex) {
            RedisCache::set($hashKey, [], 900);
        } finally {
            $redis->del("inprogress:$hashKey");
        }
    }

    public static function getSums($groupByColumn, $query, $victimsOnly, $cacheOverride = false, $addInfo = true)
    {
        global $mdb, $longQueryMS;

        $hashKey = "Stats::getSums:q:$groupByColumn:" . serialize($query) . ":" . serialize($victimsOnly);
        try {
            $result = RedisCache::get($hashKey);
            if ($cacheOverride == false && $result != null) {
                return $result;
            }

            $killmails = $mdb->getCollection('killmails');

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
            $pipeline[] = ['$match' => $query];
            if ($victimsOnly !== "null") $pipeline[] = ['$match' => ['involved.isVictim' => ($victimsOnly == "true" ? true : false)]];
            $pipeline[] = ['$group' => [
                '_id' => 0, 
                'isk' => ['$sum' => '$zkb.totalValue'], 
                'fitted' => ['$sum' => '$zkb.fittedValue'], 
                'dropped' => ['$sum' => '$zkb.droppedValue'], 
                'destroyed' => ['$sum' => '$zkb.destroyedValue'], 
                'kills' => ['$sum' => 1]
                ]
            ];

            $rr = $killmails->aggregate($pipeline, ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true, 'maxTimeMS' => 25000]);
            $result = isset($rr['result']) && !empty($rr['result']) ? $rr['result'][0] : [
                'isk' => 0,
                'fitted' => 0,
                'dropped' => 0,
                'destroyed' => 0,
                'kills' => 0
            ];

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $hashKey $uri");
            }

            RedisCache::set($hashKey, $result, 900);

            return $result;
        } catch (Exception $ex) {
            RedisCache::set($hashKey, [], 900);
        }
    }

    public static function getLabels($query, $victimsOnly)
    {
        global $mdb, $longQueryMS;

        try {
            $killmails = $mdb->getCollection('killmails');

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => $query];
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
            $result = $rr['result'];

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $uri");
            }
            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() != 50) Util::zout(print_r($ex, true)); // code 50 is query timeout
        }
    }

    public static function getDistincts($query, $filter, $victimsOnly)
    {
        global $mdb, $longQueryMS;

        $hashKey = "Stats::getDistincts:" . serialize($query) . ":" . serialize($victimsOnly);
        try {
            $killmails = $mdb->getCollection('killmails');

            $timer = new Timer();
            $pipeline = [];
            $pipeline[] = ['$match' => $query];
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
            $result = isset($rr['result'][0]) ? $rr['result'][0] : [];

            $time = $timer->stop();
            if ($time > $longQueryMS) {
                global $uri;
                Util::zout("getTop Long query (${time}ms): $uri");
            }

            return $result;
        } catch (Exception $ex) {
            if ($ex->getCode() != 50) Util::zout(print_r($ex, true));
            return [];
        }
    }

    public static function getSelectedFromBase($base, $buttons)
    {
        foreach ($buttons as $button) {
            if (Util::startsWith($button, $base)) return str_replace($base, '', $button);
        }
        return 'and'; // default
    }
}
