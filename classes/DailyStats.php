<?php

class DailyStats
{
    const COLLECTION = 'dailystats';

    public static $types = [
        'characterID' => ['label' => 'character', 'field' => 'involved.characterID', 'entitySide' => true],
        'corporationID' => ['label' => 'corporation', 'field' => 'involved.corporationID', 'entitySide' => true],
        'allianceID' => ['label' => 'alliance', 'field' => 'involved.allianceID', 'entitySide' => true],
        'factionID' => ['label' => 'faction', 'field' => 'involved.factionID', 'entitySide' => true],
        'shipTypeID' => ['label' => 'ship', 'field' => 'involved.shipTypeID', 'entitySide' => true],
        'groupID' => ['label' => 'group', 'field' => 'involved.groupID', 'entitySide' => true],
        'solarSystemID' => ['label' => 'system', 'field' => 'system.solarSystemID', 'entitySide' => false],
        'constellationID' => ['label' => 'constellation', 'field' => 'system.constellationID', 'entitySide' => false],
        'regionID' => ['label' => 'region', 'field' => 'system.regionID', 'entitySide' => false],
        'locationID' => ['label' => 'location', 'field' => 'locationID', 'entitySide' => false],
        'label' => ['label' => 'label', 'field' => 'labels', 'entitySide' => false],
    ];

    public static $topTypes = [
        'characterID' => ['label' => 'character', 'field' => 'involved.characterID'],
        'corporationID' => ['label' => 'corporation', 'field' => 'involved.corporationID'],
        'allianceID' => ['label' => 'alliance', 'field' => 'involved.allianceID'],
        'factionID' => ['label' => 'faction', 'field' => 'involved.factionID'],
        'shipTypeID' => ['label' => 'ship', 'field' => 'involved.shipTypeID'],
        'groupID' => ['label' => 'group', 'field' => 'involved.groupID'],
        'solarSystemID' => ['label' => 'system', 'field' => 'system.solarSystemID'],
        'regionID' => ['label' => 'region', 'field' => 'system.regionID'],
        'locationID' => ['label' => 'location', 'field' => 'locationID'],
    ];

    public static function normalizeType($type)
    {
        $map = [
            'character' => 'characterID',
            'corporation' => 'corporationID',
            'alliance' => 'allianceID',
            'faction' => 'factionID',
            'ship' => 'shipTypeID',
            'shipType' => 'shipTypeID',
            'group' => 'groupID',
            'system' => 'solarSystemID',
            'solarSystem' => 'solarSystemID',
            'constellation' => 'constellationID',
            'region' => 'regionID',
            'location' => 'locationID',
            'label' => 'label',
        ];
        return isset($map[$type]) ? $map[$type] : $type;
    }

    public static function dayFromKillmail($killmail)
    {
        if (isset($killmail['dttm']) && $killmail['dttm'] instanceof MongoDB\BSON\UTCDateTime) {
            return $killmail['dttm']->toDateTime()->format('Y-m-d');
        }
        return gmdate('Y-m-d', strtotime((string) @$killmail['dttm']));
    }

    public static function markDirtySequence($type, $id, $day, $sequence)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type]) || $id === null || $id === '' || $id === 0 || $id === '0') {
            return;
        }

        $id = $type == 'label' ? (string) $id : (int) $id;
        $sequence = (int) $sequence;
        if ($sequence <= 0) {
            return;
        }
        $key = ['type' => $type, 'id' => $id, 'day' => $day];
        if (self::isToday($day)) {
            $mdb->getCollection(self::COLLECTION)->updateOne($key, [
                '$setOnInsert' => $key + ['created' => time()],
            ], ['upsert' => true]);
            $mdb->getCollection(self::COLLECTION)->updateOne($key + [
                '$or' => [
                    ['update' => ['$exists' => false]],
                    ['update' => ['$lte' => 0]],
                ],
            ], [
                '$set' => ['update' => -1],
            ]);
            return;
        }

        $mdb->getCollection(self::COLLECTION)->updateOne($key, [
            '$setOnInsert' => $key + ['created' => time()],
            '$max' => ['update' => $sequence],
        ], ['upsert' => true]);
    }

    public static function isToday($day)
    {
        return $day == gmdate('Y-m-d');
    }

    public static function releaseToday()
    {
        global $mdb;

        return $mdb->getCollection(self::COLLECTION)->updateMany(
            ['day' => gmdate('Y-m-d'), 'update' => -1],
            ['$set' => ['update' => 1]]
        );
    }

    public static function markDirtyFromKillmail($killmail)
    {
        $keys = self::keysFromKillmail($killmail);
        $sequence = (int) @$killmail['sequence'];
        foreach ($keys as $key) {
            self::markDirtySequence($key['type'], $key['id'], $key['day'], $sequence);
        }

        return count($keys);
    }

    public static function keysFromKillmail($killmail)
    {
        if (!isset($killmail['dttm'])) {
            return [];
        }

        $day = self::dayFromKillmail($killmail);
        $keys = [];
        $mark = function ($type, $id) use ($day, &$keys) {
            $type = DailyStats::normalizeType($type);
            if (!isset(DailyStats::$types[$type]) || $id === null || $id === '') {
                return;
            }
            if ($type != 'label' && (int) $id == 0) {
                return;
            }
            $id = $type == 'label' ? (string) $id : (int) $id;
            $key = DailyStats::queueValue($type, $id, $day);
            $keys[$key] = ['type' => $type, 'id' => $id, 'day' => $day];
        };

        foreach ((array) @$killmail['involved'] as $involved) {
            foreach (['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'] as $type) {
                $mark($type, @$involved[$type]);
            }
        }

        foreach (['solarSystemID', 'constellationID', 'regionID'] as $type) {
            $mark($type, @$killmail['system'][$type]);
        }
        $mark('locationID', @$killmail['locationID']);

        $mark('label', 'all');
        foreach ((array) @$killmail['labels'] as $label) {
            $mark('label', $label);
        }

        return array_values($keys);
    }

    public static function queueValue($type, $id, $day)
    {
        return "$type:$id:$day";
    }

    public static function rebuild($type, $id, $day, $expectedUpdate = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            throw new Exception("Unknown daily stats type: $type");
        }

        $id = $type == 'label' ? (string) $id : (int) $id;
        $doc = [
            'type' => $type,
            'id' => $id,
            'day' => $day,
            'updated' => time(),
            'kills' => self::sideStats($type, $id, $day, false),
            'losses' => self::sideStats($type, $id, $day, true),
        ];

        $hasActivity = (
            ((int) @$doc['kills']['summary']['count'] > 0) ||
            ((int) @$doc['losses']['summary']['count'] > 0)
        );

        if (!$hasActivity) {
            $deleteKey = ['type' => $type, 'id' => $id, 'day' => $day];
            if ($expectedUpdate !== null) {
                $deleteKey['update'] = (int) $expectedUpdate;
            }
            $mdb->getCollection(self::COLLECTION)->deleteOne($deleteKey);
            return null;
        }

        $mdb->getCollection(self::COLLECTION)->updateOne(
            ['type' => $type, 'id' => $id, 'day' => $day],
            ['$set' => $doc],
            ['upsert' => true]
        );

        return $doc;
    }

    public static function get($type, $id, $day = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $baseQuery = ['type' => $type, 'id' => $id, 'updated' => ['$exists' => true]];
        if ($day === null) {
            $day = $mdb->findField(self::COLLECTION, 'day', $baseQuery, ['day' => -1]);
        }
        if ($day == null) {
            return null;
        }

        $doc = $mdb->findDoc(self::COLLECTION, $baseQuery + ['day' => $day]);
        if ($doc != null) {
            self::hydrateForView($doc);
        }

        return $doc;
    }

    public static function getAggregate($type, $id, $days = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $query = ['type' => $type, 'id' => $id, 'updated' => ['$exists' => true]];
        if (is_array($days) && count($days) > 0) {
            $query['day'] = ['$in' => array_values($days)];
        }

        $docs = $mdb->find(self::COLLECTION, $query, ['day' => -1], null, [
            'day' => 1,
            'kills' => 1,
            'losses' => 1,
        ]);
        if (count($docs) == 0) {
            return null;
        }

        $doc = [
            'type' => $type,
            'id' => $id,
            'day' => count($docs) == 1 ? $docs[0]['day'] : null,
            'days' => array_values(array_map(function ($row) { return $row['day']; }, $docs)),
            'kills' => self::emptySideAggregate(),
            'losses' => self::emptySideAggregate(),
        ];

        foreach ($docs as $dailyDoc) {
            foreach (['kills', 'losses'] as $sideName) {
                self::mergeSideAggregate($doc[$sideName], is_object($dailyDoc[$sideName] ?? null) ? (array) $dailyDoc[$sideName] : ($dailyDoc[$sideName] ?? []));
            }
        }

        foreach (['kills', 'losses'] as $sideName) {
            self::finishSideAggregate($doc[$sideName]);
        }
        self::hydrateForView($doc);

        return $doc;
    }

    public static function getDays($type, $id, $limit = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        return $mdb->find(self::COLLECTION, ['type' => $type, 'id' => $id, 'updated' => ['$exists' => true]], ['day' => -1], $limit, [
            'day' => 1,
            'kills.summary.count' => 1,
            'kills.summary.isk' => 1,
            'kills.summary.points' => 1,
            'losses.summary.count' => 1,
            'losses.summary.isk' => 1,
            'losses.summary.points' => 1,
        ]);
    }

    private static function emptySideAggregate()
    {
        $top = [];
        foreach (array_keys(self::$topTypes) as $type) {
            $top[$type] = [];
        }
        return [
            'summary' => ['count' => 0, 'isk' => 0, 'points' => 0],
            'labels' => [],
            'top' => $top,
            'topValueKillIDs' => [],
        ];
    }

    private static function mergeSideAggregate(&$aggregate, $side)
    {
        $side = is_object($side) ? (array) $side : (array) $side;
        $summary = is_object($side['summary'] ?? null) ? (array) $side['summary'] : ($side['summary'] ?? []);
        $aggregate['summary']['count'] += (int) ($summary['count'] ?? 0);
        $aggregate['summary']['isk'] += (double) ($summary['isk'] ?? 0);
        $aggregate['summary']['points'] += (int) ($summary['points'] ?? 0);

        foreach ((array) ($side['labels'] ?? []) as $row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            $label = (string) ($row['label'] ?? '');
            if ($label == '') {
                continue;
            }
            if (!isset($aggregate['labels'][$label])) {
                $aggregate['labels'][$label] = ['label' => $label, 'count' => 0, 'isk' => 0];
            }
            $aggregate['labels'][$label]['count'] += (int) ($row['count'] ?? 0);
            $aggregate['labels'][$label]['isk'] += (double) ($row['isk'] ?? 0);
        }

        foreach ((array) ($side['top'] ?? []) as $type => $rows) {
            if (!isset(self::$topTypes[$type])) {
                continue;
            }
            foreach ((array) $rows as $row) {
                $row = is_object($row) ? (array) $row : (array) $row;
                $entityID = (int) ($row[$type] ?? 0);
                if ($entityID == 0) {
                    continue;
                }
                if (!isset($aggregate['top'][$type][$entityID])) {
                    $aggregate['top'][$type][$entityID] = [$type => $entityID, 'kills' => 0, 'isk' => 0];
                }
                $aggregate['top'][$type][$entityID]['kills'] += (int) ($row['kills'] ?? 0);
                $aggregate['top'][$type][$entityID]['isk'] += (double) ($row['isk'] ?? 0);
            }
        }

        foreach ((array) ($side['topValueKillIDs'] ?? []) as $killID) {
            $killID = (int) $killID;
            if ($killID > 0) {
                $aggregate['topValueKillIDs'][$killID] = $killID;
            }
        }
    }

    private static function finishSideAggregate(&$side)
    {
        $side['labels'] = array_values($side['labels']);
        usort($side['labels'], function ($a, $b) {
            if ($a['count'] == $b['count']) {
                return $b['isk'] <=> $a['isk'];
            }
            return $b['count'] <=> $a['count'];
        });

        foreach ($side['top'] as $type => $rows) {
            $rows = array_values($rows);
            usort($rows, function ($a, $b) {
                if ($a['kills'] == $b['kills']) {
                    return $b['isk'] <=> $a['isk'];
                }
                return $b['kills'] <=> $a['kills'];
            });
            $side['top'][$type] = array_slice($rows, 0, 50);
        }

        $side['topValueKillIDs'] = self::topValueKillIDsByValue($side['topValueKillIDs']);
        $side['topValues'] = Kills::getDetails($side['topValueKillIDs'], true);
    }

    private static function topValueKillIDsByValue($killIDs)
    {
        global $mdb;

        $killIDs = array_values(array_unique(array_filter(array_map('intval', (array) $killIDs))));
        if (count($killIDs) == 0) {
            return [];
        }

        $rows = $mdb->getCollection('killmails')->find(
            ['killID' => ['$in' => $killIDs]],
            ['projection' => ['_id' => 0, 'killID' => 1, 'zkb.totalValue' => 1]]
        )->toArray();

        usort($rows, function ($a, $b) {
            $a = is_object($a) ? (array) $a : (array) $a;
            $b = is_object($b) ? (array) $b : (array) $b;
            $aZkb = is_object($a['zkb'] ?? null) ? (array) $a['zkb'] : (array) ($a['zkb'] ?? []);
            $bZkb = is_object($b['zkb'] ?? null) ? (array) $b['zkb'] : (array) ($b['zkb'] ?? []);
            $valueCompare = ((double) ($bZkb['totalValue'] ?? 0)) <=> ((double) ($aZkb['totalValue'] ?? 0));
            if ($valueCompare != 0) {
                return $valueCompare;
            }
            return ((int) ($b['killID'] ?? 0)) <=> ((int) ($a['killID'] ?? 0));
        });

        $rows = array_slice($rows, 0, 10);
        return array_values(array_map(function ($row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            return (int) ($row['killID'] ?? 0);
        }, $rows));
    }

    public static function labelRadarCharts($rows)
    {
        $groups = self::labelGroups($rows);
        self::addUnderOneBillionIskBand($groups);

        $charts = [];
        foreach ($groups as $group) {
            usort($group['rows'], function ($a, $b) {
                if (($a['count'] ?? 0) == ($b['count'] ?? 0)) {
                    return ($b['isk'] ?? 0) <=> ($a['isk'] ?? 0);
                }
                return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
            });

            $totalCount = array_sum(array_map(function ($row) { return (int) ($row['count'] ?? 0); }, $group['rows']));
            $totalIsk = array_sum(array_map(function ($row) { return (double) ($row['isk'] ?? 0); }, $group['rows']));
            $chartRows = self::topRowsWithOther($group['rows']);
            $charts[] = self::radarChart($group['title'], $chartRows, $totalCount, $totalIsk);
        }

        usort($charts, function ($a, $b) {
            return ($b['totalCount'] ?? 0) <=> ($a['totalCount'] ?? 0);
        });
        return $charts;
    }

    private static function labelGroups($rows)
    {
        $titles = [
            'loc' => 'Locations',
            'tz' => 'Time Zones',
            'cat' => 'Categories',
            'isk' => 'ISK Bands',
            '#' => 'Attacker Counts',
            'fw' => 'Faction Warfare',
        ];

        $groups = [];
        foreach ((array) $rows as $row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            $label = (string) ($row['label'] ?? '');
            if ($label == '') {
                continue;
            }

            $pos = strpos($label, ':');
            if ($pos === false) {
                $groupKey = '_labels';
                $axisLabel = $label;
            } else {
                $groupKey = substr($label, 0, $pos);
                $axisLabel = substr($label, $pos + 1);
            }

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'title' => $groupKey == '_labels' ? 'Labels' : ($titles[$groupKey] ?? strtoupper($groupKey) . ' Labels'),
                    'rows' => [],
                ];
            }
            $row['axisLabel'] = $axisLabel == '' ? $label : $axisLabel;
            $groups[$groupKey]['rows'][] = $row;
        }
        return $groups;
    }

    private static function addUnderOneBillionIskBand(&$groups)
    {
        $pvpRow = null;
        foreach (($groups['_labels']['rows'] ?? []) as $row) {
            if (($row['label'] ?? '') == 'pvp') {
                $pvpRow = $row;
                break;
            }
        }
        if ($pvpRow == null) {
            return;
        }

        if (!isset($groups['isk'])) {
            $groups['isk'] = ['title' => 'ISK Bands', 'rows' => []];
        }

        $iskCount = 0;
        $iskTotal = 0;
        foreach ($groups['isk']['rows'] as $row) {
            $iskCount += (int) ($row['count'] ?? 0);
            $iskTotal += (double) ($row['isk'] ?? 0);
        }

        $underCount = max(0, (int) ($pvpRow['count'] ?? 0) - $iskCount);
        $underIsk = max(0, (double) ($pvpRow['isk'] ?? 0) - $iskTotal);
        if ($underCount > 0 || $underIsk > 0) {
            $groups['isk']['rows'][] = [
                'label' => 'isk:<1b',
                'axisLabel' => '<1b',
                'count' => $underCount,
                'isk' => $underIsk,
            ];
        }
    }

    private static function topRowsWithOther($rows)
    {
        if (count($rows) <= 12) {
            return array_values($rows);
        }

        $topRows = array_slice($rows, 0, 11);
        $other = ['label' => 'other', 'axisLabel' => 'other', 'count' => 0, 'isk' => 0];
        foreach (array_slice($rows, 11) as $row) {
            $other['count'] += (int) ($row['count'] ?? 0);
            $other['isk'] += (double) ($row['isk'] ?? 0);
        }
        $topRows[] = $other;
        return $topRows;
    }

    private static function radarChart($title, $rows, $totalCount, $totalIsk)
    {
        $center = 150;
        $radius = 82;
        $labelRadius = 108;
        $count = count($rows);
        $max = 1;
        foreach ($rows as $row) {
            $max = max($max, (float) ($row['count'] ?? 0));
        }

        $points = [];
        $axes = [];
        foreach ($rows as $idx => $row) {
            $angle = (2 * M_PI * $idx / max(3, $count)) - (M_PI / 2);
            $pointScale = min(1, ((float) ($row['count'] ?? 0)) / $max);
            $points[] = self::radarPoint($center, $radius * $pointScale, $angle);
            $axes[] = [
                'x2' => round($center + cos($angle) * $radius, 2),
                'y2' => round($center + sin($angle) * $radius, 2),
                'labelX' => round($center + cos($angle) * $labelRadius, 2),
                'labelY' => round($center + sin($angle) * $labelRadius, 2),
                'anchor' => cos($angle) > 0.25 ? 'start' : (cos($angle) < -0.25 ? 'end' : 'middle'),
                'label' => (string) ($row['axisLabel'] ?? $row['label'] ?? ''),
                'fullLabel' => (string) ($row['label'] ?? ''),
                'count' => (int) ($row['count'] ?? 0),
                'isk' => (double) ($row['isk'] ?? 0),
                'percent' => $totalCount > 0 ? (((int) ($row['count'] ?? 0)) / $totalCount) * 100 : 0,
                'pointX' => round($center + cos($angle) * $radius * $pointScale, 2),
                'pointY' => round($center + sin($angle) * $radius * $pointScale, 2),
            ];
        }

        return [
            'title' => $title,
            'points' => implode(' ', $points),
            'axes' => $axes,
            'grids' => self::radarGrids($center, $radius, $count),
            'totalCount' => $totalCount,
            'totalIsk' => $totalIsk,
        ];
    }

    private static function radarPoint($center, $radius, $angle)
    {
        return round($center + cos($angle) * $radius, 2) . ',' . round($center + sin($angle) * $radius, 2);
    }

    private static function radarGrids($center, $radius, $count)
    {
        $grids = [];
        foreach ([0.25, 0.5, 0.75, 1] as $scale) {
            $points = [];
            for ($idx = 0; $idx < max(3, $count); $idx++) {
                $angle = (2 * M_PI * $idx / max(3, $count)) - (M_PI / 2);
                $points[] = self::radarPoint($center, $radius * $scale, $angle);
            }
            $grids[] = implode(' ', $points);
        }
        return $grids;
    }

    private static function sideStats($type, $id, $day, $losses)
    {
        $query = self::buildQuery($type, $id, $day, $losses);
        return self::facetedSideStats($query, $losses);
    }

    private static function buildQuery($type, $id, $day, $losses)
    {
        $parameters = [];
        if ($type == 'label') {
            if ($id != 'all') {
                $parameters['labels'] = $id;
            }
        } elseif (self::$types[$type]['entitySide']) {
            $parameters[$type] = $id;
            $parameters[$losses ? 'losses' : 'kills'] = true;
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        } else {
            $parameters[$type] = $id;
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        }

        return self::withDayKillIDRange(MongoFilter::buildQuery($parameters), $day);
    }

    private static function withDayKillIDRange($query, $day)
    {
        $time = strtotime($day);
        $nextTime = strtotime('+1 day', $time);
        $first = MongoFilter::getFirstKillID(date('Y', $time), date('m', $time), date('d', $time));
        $next = MongoFilter::getFirstKillID(date('Y', $nextTime), date('m', $nextTime), date('d', $nextTime));
        $dayQuery = ['killID' => ['$gte' => (int) $first, '$lt' => (int) $next]];

        if (empty($query)) {
            return $dayQuery;
        }
        if (isset($query['$and'])) {
            $query['$and'][] = $dayQuery;
            return $query;
        }
        return ['$and' => [$query, $dayQuery]];
    }

    private static function facetedSideStats($query, $losses)
    {
        global $mdb;

        $facets = [
            'summary' => self::summaryFacet(),
            'labels' => self::labelsFacet(),
            'topValueKillIDs' => self::topValueKillIDsFacet(),
        ];
        foreach (self::$topTypes as $type => $meta) {
            $facets[$type] = self::topEntityFacet($type, $meta['field'], $losses);
        }

        $rows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$facet' => $facets],
        ], self::aggregateOptions()));
        $row = $rows[0] ?? [];

        return [
            'summary' => self::normalizeSummary($row['summary'][0] ?? []),
            'labels' => (array) ($row['labels'] ?? []),
            'top' => self::normalizeTopFacets($row),
            'topValueKillIDs' => self::normalizeTopValueKillIDs($row['topValueKillIDs'] ?? []),
        ];
    }

    private static function summaryFacet()
    {
        return [
            ['$group' => [
                '_id' => null,
                'count' => ['$sum' => 1],
                'isk' => ['$sum' => '$zkb.totalValue'],
                'points' => ['$sum' => '$zkb.points'],
            ]],
            ['$project' => ['_id' => 0, 'count' => 1, 'isk' => 1, 'points' => 1]],
        ];
    }

    private static function labelsFacet()
    {
        return [
            ['$unwind' => '$labels'],
            ['$group' => [
                '_id' => '$labels',
                'count' => ['$sum' => 1],
                'isk' => ['$sum' => '$zkb.totalValue'],
            ]],
            ['$sort' => ['count' => -1, 'isk' => -1]],
            ['$project' => ['_id' => 0, 'label' => '$_id', 'count' => 1, 'isk' => 1]],
        ];
    }

    private static function topEntityFacet($type, $field, $losses)
    {
        $pipeline = [];
        if (strpos($field, 'involved.') === 0) {
            $pipeline[] = ['$unwind' => '$involved'];
            $pipeline[] = ['$match' => ['involved.isVictim' => $losses]];
        }
        $pipeline[] = ['$match' => [$field => ['$nin' => [null, 0]]]];
        $pipeline[] = ['$group' => [
            '_id' => ['killID' => '$killID', 'entityID' => '$'.$field],
            'isk' => ['$first' => '$zkb.totalValue'],
        ]];
        $pipeline[] = ['$group' => [
            '_id' => '$_id.entityID',
            'kills' => ['$sum' => 1],
            'isk' => ['$sum' => '$isk'],
        ]];
        $pipeline[] = ['$sort' => ['kills' => -1, 'isk' => -1]];
        $pipeline[] = ['$limit' => 50];
        $pipeline[] = ['$project' => ['_id' => 0, $type => '$_id', 'kills' => 1, 'isk' => 1]];

        return $pipeline;
    }

    private static function topValueKillIDsFacet()
    {
        return [
            ['$unwind' => '$involved'],
            ['$match' => ['involved.isVictim' => true, 'involved.shipTypeID' => ['$nin' => [null, 0]]]],
            ['$sort' => ['zkb.totalValue' => -1, 'killID' => -1]],
            ['$limit' => 10],
            ['$project' => ['_id' => 0, 'killID' => 1]],
        ];
    }

    private static function normalizeSummary($row)
    {
        return [
            'count' => (int) ($row['count'] ?? 0),
            'isk' => (double) ($row['isk'] ?? 0),
            'points' => (int) ($row['points'] ?? 0),
        ];
    }

    private static function normalizeTopFacets($row)
    {
        $top = [];
        foreach (array_keys(self::$topTypes) as $type) {
            $top[$type] = (array) ($row[$type] ?? []);
        }
        return $top;
    }

    private static function normalizeTopValueKillIDs($rows)
    {
        $killIDs = [];
        foreach ($rows as $row) {
            $killIDs[] = (int) $row['killID'];
        }
        return $killIDs;
    }

    private static function hydrateForView(&$doc)
    {
        foreach (['kills', 'losses'] as $sideName) {
            if (!isset($doc[$sideName]) || !is_array($doc[$sideName])) {
                continue;
            }

            $side =& $doc[$sideName];
            $topLists = [];
            foreach ((array) @$side['top'] as $type => $rows) {
                $rows = (array) $rows;
                Info::addInfo($rows);
                $topLists[] = [
                    'type' => self::$topTypes[$type]['label'] ?? $type,
                    'typeID' => $type,
                    'data' => $rows,
                ];
            }
            $side['topLists'] = $topLists;
            if (!isset($side['topValues'])) {
                $side['topValues'] = Kills::getDetails((array) @$side['topValueKillIDs'], true);
            }
        }
    }

    private static function aggregateOptions()
    {
        $options = ['allowDiskUse' => true, 'noCursorTimeout' => true];
        if (php_sapi_name() !== 'cli') {
            $options['maxTimeMS'] = 30000;
        }
        return $options;
    }
}
