<?php

class DailyStats
{
    const COLLECTION = 'stats_monthly';
    const MONTH_FIELD = 'yyyy-mm';
    const PERSIST_MIN_TOTAL = 10000;

    public static $types = [
        'characterID' => true,
        'corporationID' => true,
        'allianceID' => true,
        'factionID' => true,
        'shipTypeID' => true,
        'groupID' => true,
        'solarSystemID' => false,
        'constellationID' => false,
        'regionID' => false,
        'locationID' => false,
        'label' => false,
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

    public static function markDirtySequence($type, $id, $day, $sequence, $persistChecked = false)
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
        if (!$persistChecked && !self::shouldPersist($type, $id)) {
            return;
        }
        $month = substr($day, 0, 7);
        $key = ['type' => $type, 'id' => $id, self::MONTH_FIELD => $month];

        $mdb->getCollection(self::COLLECTION)->updateOne(
            $key,
            [
                '$setOnInsert' => $key + ['created' => time()],
                '$addToSet' => ['updates' => "$day:$sequence"],
            ],
            ['upsert' => true]
        );
    }

    public static function markDirtyFromKillmail($killmail)
    {
        $keys = self::keysFromKillmail($killmail);
        $sequence = (int) @$killmail['sequence'];
        $marked = 0;
        foreach ($keys as $key) {
            if (!self::shouldPersist($key['type'], $key['id'])) {
                continue;
            }
            self::markDirtySequence($key['type'], $key['id'], $key['day'], $sequence, true);
            $marked++;
        }

        return $marked;
    }

    public static function keysFromKillmail($killmail)
    {
        if (!isset($killmail['dttm'])) {
            return [];
        }

        $day = $killmail['dttm'] instanceof MongoDB\BSON\UTCDateTime
            ? $killmail['dttm']->toDateTime()->format('Y-m-d')
            : gmdate('Y-m-d', strtotime((string) @$killmail['dttm']));
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
            $keys["$type:$id:$day"] = ['type' => $type, 'id' => $id, 'day' => $day];
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

    public static function rebuild($type, $id, $day, $expectedUpdate = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            throw new Exception("Unknown daily stats type: $type");
        }

        $id = $type == 'label' ? (string) $id : (int) $id;
        $dayField = substr($day, 8, 2);
        $key = ['type' => $type, 'id' => $id, self::MONTH_FIELD => substr($day, 0, 7)];
        if ($expectedUpdate !== null) {
            $existing = $mdb->getCollection(self::COLLECTION)->findOne($key, ['projection' => ["$dayField.sequence" => 1]]);
            if ((int) ($existing[$dayField]['sequence'] ?? 0) >= (int) $expectedUpdate) {
                self::clearUpdate($key, $day, $expectedUpdate);
                return null;
            }
        }

        $doc = [
            'sequence' => (int) $expectedUpdate,
            'updated' => time(),
            'kills' => self::sideStats($type, $id, $day, false),
            'losses' => self::sideStats($type, $id, $day, true),
        ];

        $hasActivity = (
            ((int) @$doc['kills']['summary']['count'] > 0) ||
            ((int) @$doc['losses']['summary']['count'] > 0)
        );

        if (!$hasActivity) {
            $mdb->getCollection(self::COLLECTION)->updateOne($key, ['$unset' => [$dayField => 1]]);
            self::clearUpdate($key, $day, $expectedUpdate);
            return null;
        }

        $mdb->getCollection(self::COLLECTION)->updateOne(
            $key,
            ['$set' => [$dayField => $doc]],
            ['upsert' => true]
        );
        self::clearUpdate($key, $day, $expectedUpdate);

        return $doc;
    }

    public static function rebuildMonthly($monthlyDoc)
    {
        global $mdb;

        $type = self::normalizeType($monthlyDoc['type']);
        if (!isset(self::$types[$type])) {
            throw new Exception("Unknown daily stats type: $type");
        }

        $id = $type == 'label' ? (string) $monthlyDoc['id'] : (int) $monthlyDoc['id'];
        if (!self::shouldPersist($type, $id)) {
            $mdb->getCollection(self::COLLECTION)->deleteOne(['_id' => $monthlyDoc['_id']]);
            return ['updated' => 0, 'removed' => 0, 'pulled' => count((array) ($monthlyDoc['updates'] ?? [])), 'deleted' => true];
        }
        $updates = [];
        $pullUpdates = [];
        foreach ((array) ($monthlyDoc['updates'] ?? []) as $update) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2}):(\d+)$/', (string) $update, $matches)) {
                $pullUpdates[] = $update;
                continue;
            }
            $day = $matches[1];
            $sequence = (int) $matches[2];
            if (substr($day, 0, 7) != $monthlyDoc[self::MONTH_FIELD]) {
                $pullUpdates[] = (string) $update;
                continue;
            }
            if (!isset($updates[$day]) || $updates[$day]['sequence'] < $sequence) {
                if (isset($updates[$day])) {
                    $pullUpdates[] = $updates[$day]['value'];
                }
                $updates[$day] = ['day' => $day, 'sequence' => $sequence, 'value' => (string) $update];
            } else {
                $pullUpdates[] = (string) $update;
            }
        }

        $updates = array_values($updates);
        usort($updates, function ($a, $b) { return $b['sequence'] <=> $a['sequence']; });

        $set = [];
        $unset = [];
        foreach ($updates as $update) {
            $day = $update['day'];
            $dayField = substr($day, 8, 2);
            if ((int) ($monthlyDoc[$dayField]['sequence'] ?? 0) >= $update['sequence']) {
                $pullUpdates[] = $update['value'];
                continue;
            }

            $doc = [
                'sequence' => $update['sequence'],
                'updated' => time(),
                'kills' => self::sideStats($type, $id, $day, false),
                'losses' => self::sideStats($type, $id, $day, true),
            ];

            if (((int) @$doc['kills']['summary']['count'] > 0) || ((int) @$doc['losses']['summary']['count'] > 0)) {
                $set[$dayField] = $doc;
            } else {
                $unset[$dayField] = 1;
            }
            $pullUpdates[] = $update['value'];
        }

        $write = [];
        if (count($set) > 0) {
            $write['$set'] = $set;
        }
        if (count($unset) > 0) {
            $write['$unset'] = $unset;
        }
        if (count($pullUpdates) > 0) {
            $write['$pull'] = ['updates' => ['$in' => array_values(array_unique($pullUpdates))]];
        }
        if (count($write) > 0) {
            $mdb->getCollection(self::COLLECTION)->bulkWrite([
                ['updateOne' => [['_id' => $monthlyDoc['_id']], $write]],
            ], ['ordered' => false]);
        }

        $mdb->getCollection(self::COLLECTION)->updateOne(['_id' => $monthlyDoc['_id'], 'updates' => []], ['$unset' => ['updates' => 1]]);
        if ($mdb->getCollection(self::COLLECTION)->findOne(
            ['type' => $type, 'id' => $id, 'updates' => ['$exists' => true]],
            ['projection' => ['_id' => 1]]
        ) == null) {
            $mdb->getCollection('statistics')->updateOne(
                ['type' => $type, 'id' => $id],
                [
                    '$set' => ['dailyStatsBackfillComplete' => true],
                    '$unset' => [
                        'dailyStatsBackfillQueued' => 1,
                        'dailyStatsBackfillQueuedAt' => 1,
                        'dailyStatsBackfillCompleteAt' => 1,
                        'dailyStatsBackfillNeededAt' => 1,
                    ],
                ]
            );
        }
        return ['updated' => count($set), 'removed' => count($unset), 'pulled' => count($pullUpdates)];
    }

    private static function clearUpdate($key, $day, $sequence)
    {
        global $mdb;

        $collection = $mdb->getCollection(self::COLLECTION);
        if ($sequence === null) {
            return;
        }

        $collection->updateOne($key, ['$pull' => ['updates' => "$day:" . (int) $sequence]]);
        $collection->updateOne($key + ['updates' => []], ['$unset' => ['updates' => 1]]);
    }

    public static function getAggregate($type, $id, $days = null, $viewSide = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        if (!self::shouldPersist($type, $id)) {
            return self::getAggregateOnDemand($type, $id, $days, $viewSide);
        }
        $query = ['type' => $type, 'id' => $id];
        if (is_array($days) && count($days) > 0) {
            $months = [];
            foreach ($days as $day) {
                $months[substr($day, 0, 7)] = substr($day, 0, 7);
            }
            $query[self::MONTH_FIELD] = ['$in' => array_values($months)];
        }

        $docs = self::dailyDocs($mdb->find(self::COLLECTION, $query, [self::MONTH_FIELD => -1]), is_array($days) ? array_fill_keys($days, true) : null);
        if (count($docs) == 0) return null;

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

        $viewSides = in_array($viewSide, ['kills', 'losses']) ? [$viewSide] : ['kills', 'losses'];
        foreach (['kills', 'losses'] as $sideName) {
            self::finishSideAggregate($doc[$sideName], $type, $id, in_array($sideName, $viewSides));
        }
        self::hydrateForView($doc, $viewSides);

        return $doc;
    }

    public static function getDays($type, $id, $limit = null)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        if (!self::shouldPersist($type, $id)) {
            return array_slice(self::getDaysOnDemand($type, $id), 0, $limit);
        }

        $query = ['type' => $type, 'id' => $id];
        $projection = [self::MONTH_FIELD => 1];
        for ($dayNum = 1; $dayNum <= 31; $dayNum++) {
            $dayField = sprintf('%02d', $dayNum);
            $projection["$dayField.day"] = 1;
            $projection["$dayField.kills.summary"] = 1;
            $projection["$dayField.losses.summary"] = 1;
        }

        $monthlyDocs = $mdb->getCollection(self::COLLECTION)->find($query, [
            'projection' => $projection,
            'sort' => [self::MONTH_FIELD => -1],
        ]);

        return self::dailyDocs($monthlyDocs, null, $limit);
    }

    public static function hasData($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        return self::totalStats($type, $id) > 0;
    }

    public static function runQueuedQuery($job)
    {
        $type = self::normalizeType($job['type'] ?? '');
        $id = $type == 'label' ? (string) ($job['id'] ?? '') : (int) ($job['id'] ?? 0);
        $side = in_array(($job['side'] ?? 'kills'), ['kills', 'losses']) ? $job['side'] : 'kills';
        $part = in_array(($job['part'] ?? 'history'), ['history', 'summary', 'topvalues', 'toplists', 'labels']) ? $job['part'] : 'history';
        $selectedDays = self::selectedDaysFromJob($job);
        $dailySelectionAll = count($selectedDays) == 0;
        $useDailyHistory = self::isSingleMonthHistory($selectedDays, $job);

        $dailyStats = [
            'type' => $type,
            'id' => $id,
            'day' => count($selectedDays) == 1 ? $selectedDays[0] : null,
            'days' => $selectedDays,
            'kills' => self::emptySideAggregate(),
            'losses' => self::emptySideAggregate(),
        ];
        $dailyDays = [];

        if ($part == 'history') {
            if ($useDailyHistory) {
                $dailyDays = self::shouldPersist($type, $id) ? self::getDays($type, $id) : self::getDaysOnDemand($type, $id);
                $historyWindow = self::singleMonthHistoryWindow($selectedDays, $job);
                if ($historyWindow != null) {
                    $cursor = new DateTimeImmutable($historyWindow[0], new DateTimeZone('UTC'));
                    $end = new DateTimeImmutable($historyWindow[1], new DateTimeZone('UTC'));
                    $wanted = [];
                    while ($cursor <= $end) {
                        $wanted[$cursor->format('Y-m-d')] = true;
                        $cursor = $cursor->modify('+1 day');
                    }
                    $dailyDays = array_values(array_filter($dailyDays, function ($row) use ($wanted) {
                        return isset($wanted[$row['day'] ?? '']);
                    }));
                    $byDay = [];
                    foreach ($dailyDays as $row) {
                        $byDay[$row['day']] = $row;
                    }
                    foreach ($historyWindow as $edgeDay) {
                        if (!isset($byDay[$edgeDay])) {
                            $byDay[$edgeDay] = [
                                'day' => $edgeDay,
                                'kills' => self::emptySideAggregate(),
                                'losses' => self::emptySideAggregate(),
                            ];
                        }
                    }
                    ksort($byDay);
                    $dailyDays = array_values($byDay);
                }
            } else {
                $dailyDays = self::getMonthsFromStatistics($type, $id);
            }
            $dailyStats['days'] = array_values(array_map(function ($row) { return $row['day']; }, $dailyDays));
        } else if (self::shouldPersist($type, $id)) {
            $persisted = self::getAggregate($type, $id, count($selectedDays) > 0 ? $selectedDays : null, $side);
            if ($persisted == null) return ['processing' => true];
            $dailyStats = $persisted;
        } else if ($part == 'summary') {
            $dailyStats[$side]['summary'] = self::summaryOnDemand($type, $id, $selectedDays, $side == 'losses');
        } else if ($part == 'topvalues') {
            $dailyStats[$side]['topValues'] = self::topValuesOnDemand($type, $id, $selectedDays, $side == 'losses');
        } else if ($part == 'toplists') {
            $dailyStats[$side]['topLists'] = self::topListsOnDemand($type, $id, $selectedDays, $side == 'losses');
        } else if ($part == 'labels') {
            $query = self::buildQueryForSelectedDays($type, $id, $selectedDays, $side == 'losses');
            $dailyStats[$side]['labels'] = self::onDemandLabels($query);
        }

        $start = count($selectedDays) > 0 ? min($selectedDays) : null;
        $end = count($selectedDays) > 0 ? max($selectedDays) : null;
        if (($job['days'] ?? '') == 'all') {
            $allDays = $dailyDays ?: self::getMonthsFromStatistics($type, $id);
            if (count($allDays) > 0) {
                $dayNames = array_values(array_map(function ($row) { return $row['day']; }, $allDays));
                $start = min($dayNames);
                $end = max(array_values(array_map(function ($row) { return $row['periodEnd'] ?? $row['day']; }, $allDays)));
            }
        }

        return [
            'dailyStats' => $dailyStats,
            'dailyDays' => $dailyDays,
            'dailySide' => $side,
            'dailySelectedDays' => $selectedDays,
            'dailySelectedStart' => $start,
            'dailySelectedEnd' => $end,
            'dailySelectionAll' => $dailySelectionAll,
            'dailyGraphStart' => null,
            'dailyGraphEnd' => null,
            'dailyDate' => count($selectedDays) == 1 ? $selectedDays[0] : null,
            'entityType' => $job['entityType'] ?? self::entityTypeFromStatType($type),
            'entityID' => $id,
            'key' => $job['entityType'] ?? self::entityTypeFromStatType($type),
            'id' => $id,
        ];
    }

    private static function isSingleMonthHistory($selectedDays, $job)
    {
        $ranges = [];
        if (count($selectedDays) > 0) {
            $ranges[] = [min($selectedDays), max($selectedDays)];
        }
        foreach (['graph', 'days'] as $field) {
            $value = (string) ($job[$field] ?? '');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $value, $matches)) {
                $ranges[] = [$matches[1], $matches[2]];
            } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $ranges[] = [$value, $value];
            }
        }
        if (isset($job['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $job['date'])) {
            $ranges[] = [(string) $job['date'], (string) $job['date']];
        }

        foreach ($ranges as $range) {
            if (substr($range[0], 0, 7) == substr($range[1], 0, 7)) {
                return true;
            }
        }
        return false;
    }

    private static function singleMonthHistoryWindow($selectedDays, $job)
    {
        $ranges = [];
        if (count($selectedDays) > 0) {
            $ranges[] = [min($selectedDays), max($selectedDays)];
        }
        foreach (['graph', 'days'] as $field) {
            $value = (string) ($job[$field] ?? '');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $value, $matches)) {
                $ranges[] = [$matches[1], $matches[2]];
            } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $ranges[] = [$value, $value];
            }
        }
        if (isset($job['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $job['date'])) {
            $ranges[] = [(string) $job['date'], (string) $job['date']];
        }

        foreach ($ranges as $range) {
            if (substr($range[0], 0, 7) != substr($range[1], 0, 7)) {
                continue;
            }
            $start = substr($range[0], 0, 7) . '-01';
            return [$start, gmdate('Y-m-t', strtotime($start . ' 00:00:00 UTC'))];
        }

        return null;
    }

    public static function shouldPersist($type, $id)
    {
        return self::totalStats($type, $id) >= self::PERSIST_MIN_TOTAL;
    }

    public static function queueBackfill($type, $id)
    {
        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            return 0;
        }
        $id = $type == 'label' ? (string) $id : (int) $id;
        if ($id === '' || ($type != 'label' && $id == 0) || !self::shouldPersist($type, $id)) {
            return 0;
        }

        return self::populateBackfill($type, $id);
    }

    public static function populateBackfill($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            return 0;
        }
        $id = $type == 'label' ? (string) $id : (int) $id;
        if ($id === '' || ($type != 'label' && $id == 0) || !self::shouldPersist($type, $id)) {
            return 0;
        }

        $statsKey = ['type' => $type, 'id' => $id];
        $existing = [];
        $pendingUpdates = 0;
        $projection = [self::MONTH_FIELD => 1, 'updates' => 1];
        for ($dayNum = 1; $dayNum <= 31; $dayNum++) {
            $dayField = sprintf('%02d', $dayNum);
            $projection["$dayField.day"] = 1;
            $projection["$dayField.sequence"] = 1;
        }
        $monthlyDocs = $mdb->getCollection(self::COLLECTION)->find($statsKey, [
            'projection' => $projection,
            'sort' => [self::MONTH_FIELD => -1],
        ]);
        foreach ($monthlyDocs as $monthlyDoc) {
            $month = (string) ($monthlyDoc[self::MONTH_FIELD] ?? '');
            if ($month == '') {
                continue;
            }
            foreach ((array) ($monthlyDoc['updates'] ?? []) as $update) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2}):(\d+)$/', (string) $update, $matches)) {
                    $existing[$matches[1]] = max((int) ($existing[$matches[1]] ?? 0), (int) $matches[2]);
                    $pendingUpdates++;
                }
            }
            for ($dayNum = 1; $dayNum <= 31; $dayNum++) {
                $dayField = sprintf('%02d', $dayNum);
                if (!isset($monthlyDoc[$dayField]) || !is_array($monthlyDoc[$dayField])) {
                    continue;
                }
                $day = (string) ($monthlyDoc[$dayField]['day'] ?? "$month-$dayField");
                $existing[$day] = max((int) ($existing[$day] ?? 0), (int) ($monthlyDoc[$dayField]['sequence'] ?? 0));
            }
        }

        $query = self::buildBaseQuery($type, $id);
        $rows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$group' => [
                '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$dttm']],
                'sequence' => ['$max' => '$sequence'],
            ]],
            ['$project' => ['_id' => 0, 'day' => '$_id', 'sequence' => 1]],
        ], ['allowDiskUse' => true]));

        $ops = [];
        foreach ($rows as $row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            $day = (string) ($row['day'] ?? '');
            $sequence = (int) ($row['sequence'] ?? 0);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $sequence <= 0) {
                continue;
            }

            if ((int) ($existing[$day] ?? 0) >= $sequence) {
                continue;
            }
            $month = substr($day, 0, 7);
            $key = ['type' => $type, 'id' => $id, self::MONTH_FIELD => $month];
            $ops[] = ['updateOne' => [
                $key,
                [
                    '$setOnInsert' => $key + ['created' => time()],
                    '$addToSet' => ['updates' => "$day:$sequence"],
                ],
                ['upsert' => true],
            ]];
        }

        if (count($ops) > 0) {
            $mdb->getCollection(self::COLLECTION)->bulkWrite($ops, ['ordered' => false]);
        }

        $mdb->getCollection('statistics')->updateOne(
            $statsKey,
            [
                '$set' => count($ops) > 0 ? [
                    'dailyStatsBackfillComplete' => false,
                    'dailyStatsBackfillQueued' => true,
                    'dailyStatsBackfillQueuedAt' => time(),
                ] : ['dailyStatsBackfillComplete' => $pendingUpdates == 0],
                '$unset' => count($ops) > 0 ? [
                    'dailyStatsBackfillCompleteAt' => 1,
                    'dailyStatsBackfillNeededAt' => 1,
                ] : [
                    'dailyStatsBackfillQueued' => 1,
                    'dailyStatsBackfillQueuedAt' => 1,
                    'dailyStatsBackfillCompleteAt' => 1,
                    'dailyStatsBackfillNeededAt' => 1,
                ],
            ]
        );

        return count($ops);
    }

    private static function totalStats($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            return 0;
        }

        $id = $type == 'label' ? (string) $id : (int) $id;
        $stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
        return (int) ($stats['shipsDestroyed'] ?? 0) + (int) ($stats['shipsLost'] ?? 0);
    }

    private static function getMonthsFromStatistics($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) {
            return [];
        }

        $id = $type == 'label' ? (string) $id : (int) $id;
        $stats = $mdb->findDoc('statistics', ['type' => $type, 'id' => $id]);
        $months = (array) ($stats['months'] ?? []);
        if (count($months) == 0) {
            return [];
        }
        $byMonth = [];
        foreach ($months as $monthStats) {
            $monthStats = is_object($monthStats) ? (array) $monthStats : (array) $monthStats;
            $year = (int) ($monthStats['year'] ?? 0);
            $month = (int) ($monthStats['month'] ?? 0);
            if ($year <= 0 || $month <= 0 || $month > 12) {
                continue;
            }
            $byMonth[sprintf('%04d-%02d', $year, $month)] = $monthStats;
        }
        if (count($byMonth) == 0) {
            return [];
        }
        ksort($byMonth);

        $rows = [];
        $cursor = new DateTimeImmutable(array_key_first($byMonth) . '-01', new DateTimeZone('UTC'));
        $end = new DateTimeImmutable(array_key_last($byMonth) . '-01', new DateTimeZone('UTC'));
        while ($cursor <= $end) {
            $monthKey = $cursor->format('Y-m');
            $monthStats = $byMonth[$monthKey] ?? [];
            $day = $monthKey . '-01';
            $rows[] = [
                'day' => $day,
                'periodEnd' => gmdate('Y-m-t', strtotime($day . ' 00:00:00 UTC')),
                'kills' => ['summary' => [
                    'count' => (int) ($monthStats['shipsDestroyed'] ?? 0),
                    'isk' => (double) ($monthStats['iskDestroyed'] ?? 0),
                    'points' => (int) ($monthStats['pointsDestroyed'] ?? 0),
                ]],
                'losses' => ['summary' => [
                    'count' => (int) ($monthStats['shipsLost'] ?? 0),
                    'isk' => (double) ($monthStats['iskLost'] ?? 0),
                    'points' => (int) ($monthStats['pointsLost'] ?? 0),
                ]],
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $rows;
    }

    private static function getAggregateOnDemand($type, $id, $days = null, $viewSide = null)
    {
        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $dayRows = self::getDaysOnDemand($type, $id);
        if (count($dayRows) == 0) {
            return null;
        }

        $wanted = null;
        if (is_array($days) && count($days) > 0) {
            $wanted = array_fill_keys($days, true);
            $dayRows = array_values(array_filter($dayRows, function ($row) use ($wanted) {
                return isset($wanted[$row['day'] ?? '']);
            }));
        }
        if (count($dayRows) == 0) {
            return null;
        }

        $selectedDays = array_values(array_map(function ($row) { return $row['day']; }, $dayRows));
        $doc = [
            'type' => $type,
            'id' => $id,
            'day' => count($selectedDays) == 1 ? $selectedDays[0] : null,
            'days' => $selectedDays,
            'kills' => self::onDemandSideStats($type, $id, $selectedDays, false),
            'losses' => self::onDemandSideStats($type, $id, $selectedDays, true),
        ];

        $viewSides = in_array($viewSide, ['kills', 'losses']) ? [$viewSide] : ['kills', 'losses'];
        foreach (['kills', 'losses'] as $sideName) {
            self::finishSideAggregate($doc[$sideName], $type, $id, in_array($sideName, $viewSides));
        }
        self::hydrateForView($doc, $viewSides);

        return $doc;
    }

    private static function getDaysOnDemand($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $query = self::buildBaseQuery($type, $id);
        $rows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$group' => [
                '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$dttm']],
                'sequence' => ['$max' => '$sequence'],
            ]],
            ['$sort' => ['_id' => -1]],
            ['$project' => ['_id' => 0, 'day' => '$_id', 'sequence' => 1]],
        ], ['allowDiskUse' => true]));

        $days = array_map(function ($row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            return [
                'day' => (string) ($row['day'] ?? ''),
                'sequence' => (int) ($row['sequence'] ?? 0),
                'kills' => ['summary' => ['count' => 0, 'isk' => 0, 'points' => 0]],
                'losses' => ['summary' => ['count' => 0, 'isk' => 0, 'points' => 0]],
            ];
        }, $rows);

        $summaries = [
            'kills' => self::onDemandDailySummaries($type, $id, false),
            'losses' => self::onDemandDailySummaries($type, $id, true),
        ];

        $byDay = [];
        foreach ($days as $idx => $row) {
            $byDay[$row['day']] = $idx;
        }
        foreach (['kills', 'losses'] as $sideName) {
            foreach ((array) ($summaries[$sideName] ?? []) as $summary) {
                $summary = is_object($summary) ? (array) $summary : (array) $summary;
                $day = (string) ($summary['day'] ?? '');
                if (isset($byDay[$day])) {
                    $days[$byDay[$day]][$sideName]['summary'] = [
                        'count' => (int) ($summary['count'] ?? 0),
                        'isk' => (double) ($summary['isk'] ?? 0),
                        'points' => (int) ($summary['points'] ?? 0),
                    ];
                }
            }
        }

        return $days;
    }

    private static function onDemandSideStats($type, $id, $days, $losses)
    {
        global $mdb;

        $query = self::buildQueryForDays($type, $id, $days, $losses);
        $summary = AdvancedSearch::getSums($type, $query, 'null', false, false);
        $pointsRows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$group' => ['_id' => null, 'points' => ['$sum' => '$zkb.points']]],
        ], ['allowDiskUse' => true]));

        $stats = [
            'summary' => [
                'count' => (int) ($summary['kills'] ?? 0),
                'isk' => (double) ($summary['isk'] ?? 0),
                'points' => (int) ($pointsRows[0]['points'] ?? 0),
            ],
            'labels' => self::onDemandLabels($query),
            'top' => [],
            'topValueKillIDs' => self::onDemandTopValueKillIDs($type, $id, $days, $losses),
        ];

        foreach (self::$topTypes as $topType => $meta) {
            $stats['top'][$topType] = AdvancedSearch::getTop($topType, $query, $losses ? 'true' : 'false', [], false, 'killID', -1);
        }
        self::ensureCurrentEntityTopRow($stats, $type, $id);

        return $stats;
    }

    private static function buildBaseQuery($type, $id)
    {
        $parameters = [];
        if ($type == 'label') {
            if ($id != 'all') {
                $parameters['labels'] = $id;
            }
        } else {
            $parameters[$type] = $id;
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        }

        return MongoFilter::buildQuery($parameters);
    }

    private static function buildParameters($type, $id, $day = null, $losses = null)
    {
        $parameters = [];
        if ($type == 'label') {
            if ($id != 'all') {
                $parameters['labels'] = $id;
            }
        } elseif (self::$types[$type]) {
            $parameters[$type] = $id;
            if ($losses !== null) {
                $parameters[$losses ? 'losses' : 'kills'] = true;
            }
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        } else {
            $parameters[$type] = $id;
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        }
        if ($day !== null) {
            $parameters['date'] = $day;
        }

        return $parameters;
    }

    private static function buildQueryForDays($type, $id, $days, $losses)
    {
        $query = self::buildQuery($type, $id, $days[0], $losses);
        if (count($days) == 1) {
            return $query;
        }

        $ranges = [];
        foreach ($days as $day) {
            $dayQuery = self::withDayKillIDRange([], $day);
            if (isset($dayQuery['killID'])) {
                $ranges[] = $dayQuery;
            }
        }
        if (count($ranges) == 0) {
            return ['killID' => 0];
        }

        $baseQuery = self::buildQuery($type, $id, $days[0], $losses);
        $baseParts = isset($baseQuery['$and']) ? $baseQuery['$and'] : [$baseQuery];
        $baseParts = array_values(array_filter($baseParts, function ($part) {
            return !isset($part['killID']);
        }));
        $baseParts[] = count($ranges) == 1 ? $ranges[0] : ['$or' => $ranges];

        return count($baseParts) == 1 ? $baseParts[0] : ['$and' => $baseParts];
    }

    private static function onDemandDailySummaries($type, $id, $losses)
    {
        global $mdb;

        $parameters = self::buildParameters($type, $id, null, $losses);
        $query = MongoFilter::buildQuery($parameters);

        return iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$group' => [
                '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$dttm']],
                'count' => ['$sum' => 1],
                'isk' => ['$sum' => '$zkb.totalValue'],
                'points' => ['$sum' => '$zkb.points'],
            ]],
            ['$project' => ['_id' => 0, 'day' => '$_id', 'count' => 1, 'isk' => 1, 'points' => 1]],
        ], ['allowDiskUse' => true]));
    }

    private static function onDemandLabels($query)
    {
        global $mdb;

        return iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$unwind' => '$labels'],
            ['$group' => [
                '_id' => '$labels',
                'count' => ['$sum' => 1],
                'isk' => ['$sum' => '$zkb.totalValue'],
            ]],
            ['$sort' => ['count' => -1, 'isk' => -1]],
            ['$project' => ['_id' => 0, 'label' => '$_id', 'count' => 1, 'isk' => 1]],
        ], ['allowDiskUse' => true]));
    }

    private static function onDemandTopValueKillIDs($type, $id, $days, $losses)
    {
        $killIDs = [];
        foreach ($days as $day) {
            $parameters = self::buildParameters($type, $id, $day, $losses);
            $parameters['limit'] = 10;
            foreach (Stats::getTopIsk($parameters) as $killID => $killmail) {
                $killID = (int) $killID;
                if ($killID > 0) {
                    $killIDs[$killID] = $killID;
                }
            }
        }

        return self::topValueKillIDsByValue($killIDs);
    }

    private static function dailyDocs($monthlyDocs, $wanted = null, $limit = null)
    {
        $docs = [];
        $limit = $limit === null ? null : max(0, (int) $limit);
        foreach ($monthlyDocs as $monthDoc) {
            $month = (string) ($monthDoc[self::MONTH_FIELD] ?? '');
            for ($dayNum = 31; $dayNum >= 1; $dayNum--) {
                $dayField = sprintf('%02d', $dayNum);
                if (!isset($monthDoc[$dayField]) || !is_array($monthDoc[$dayField])) {
                    continue;
                }
                $doc = $monthDoc[$dayField];
                $doc['day'] = $doc['day'] ?? "$month-$dayField";
                if ($wanted !== null && !isset($wanted[$doc['day']])) {
                    continue;
                }
                $docs[] = $doc;
                if ($limit !== null && count($docs) >= $limit) {
                    return $docs;
                }
            }
        }
        return $docs;
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

    private static function finishSideAggregate(&$side, $type = null, $id = null, $loadTopValues = true)
    {
        if ($type != null && $id != null) {
            self::ensureCurrentEntityTopRow($side, $type, $id);
        }

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

        if ($loadTopValues) {
            $side['topValueKillIDs'] = self::topValueKillIDsByValue($side['topValueKillIDs']);
            $side['topValues'] = Kills::getDetails($side['topValueKillIDs'], true);
        }
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

    private static function sideStats($type, $id, $day, $losses)
    {
        $query = self::buildQuery($type, $id, $day, $losses);
        return self::facetedSideStats($query, $losses, $type, $id);
    }

    private static function buildQuery($type, $id, $day, $losses)
    {
        $parameters = [];
        if ($type == 'label') {
            if ($id != 'all') {
                $parameters['labels'] = $id;
            }
        } elseif (self::$types[$type]) {
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
        $first = self::getFirstKillIDAtOrAfter($time);
        $next = self::getFirstKillIDAtOrAfter($nextTime);
        if ($first == 0) {
            return ['killID' => 0];
        }
        if ($next == 0) {
            $next = 999999999999;
        }
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

    private static function getFirstKillIDAtOrAfter($time)
    {
        global $mdb, $kvc;

        $time = $time - ($time % 86400);
        $cacheKey = 'zkb:firstkillid:after:' . gmdate('Ymd', $time);
        $cached = (int) $kvc->get($cacheKey);
        if ($cached > 0) {
            return $cached;
        }

        $rows = $mdb->getCollection('killmails')->find(
            ['dttm' => ['$gte' => new MongoDB\BSON\UTCDateTime($time * 1000)]],
            [
                'projection' => ['_id' => 0, 'killID' => 1],
                'sort' => ['dttm' => 1, 'killID' => 1],
                'limit' => 1,
            ]
        )->toArray();

        $killID = (int) ($rows[0]['killID'] ?? 0);
        if ($killID > 0) {
            $kvc->setex($cacheKey, 86400, $killID);
        }
        return $killID;
    }

    private static function facetedSideStats($query, $losses, $type, $id)
    {
        global $mdb;

        $facets = [
            'summary' => [
                ['$group' => [
                    '_id' => null,
                    'count' => ['$sum' => 1],
                    'isk' => ['$sum' => '$zkb.totalValue'],
                    'points' => ['$sum' => '$zkb.points'],
                ]],
                ['$project' => ['_id' => 0, 'count' => 1, 'isk' => 1, 'points' => 1]],
            ],
            'labels' => [
                ['$unwind' => '$labels'],
                ['$group' => [
                    '_id' => '$labels',
                    'count' => ['$sum' => 1],
                    'isk' => ['$sum' => '$zkb.totalValue'],
                ]],
                ['$sort' => ['count' => -1, 'isk' => -1]],
                ['$project' => ['_id' => 0, 'label' => '$_id', 'count' => 1, 'isk' => 1]],
            ],
            'topValueKillIDs' => [
                ['$unwind' => '$involved'],
                ['$match' => ['involved.isVictim' => true, 'involved.shipTypeID' => ['$nin' => [null, 0]]]],
                ['$sort' => ['zkb.totalValue' => -1, 'killID' => -1]],
                ['$limit' => 10],
                ['$project' => ['_id' => 0, 'killID' => 1]],
            ],
        ];
        foreach (self::$topTypes as $topType => $meta) {
            $facets[$topType] = self::topEntityFacet($topType, $meta['field'], $losses);
        }

        $rows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$facet' => $facets],
        ], ['allowDiskUse' => true]));
        $row = $rows[0] ?? [];
        $summary = $row['summary'][0] ?? [];

        $stats = [
            'summary' => [
                'count' => (int) ($summary['count'] ?? 0),
                'isk' => (double) ($summary['isk'] ?? 0),
                'points' => (int) ($summary['points'] ?? 0),
            ],
            'labels' => (array) ($row['labels'] ?? []),
            'top' => [],
            'topValueKillIDs' => [],
        ];
        foreach (array_keys(self::$topTypes) as $topType) {
            $stats['top'][$topType] = (array) ($row[$topType] ?? []);
        }
        foreach ((array) ($row['topValueKillIDs'] ?? []) as $topValue) {
            $stats['topValueKillIDs'][] = (int) $topValue['killID'];
        }
        self::ensureCurrentEntityTopRow($stats, $type, $id);
        return $stats;
    }

    private static function ensureCurrentEntityTopRow(&$stats, $type, $id)
    {
        if (!isset(self::$topTypes[$type])) {
            return;
        }

        $summary = $stats['summary'] ?? [];
        $count = (int) ($summary['count'] ?? 0);
        if ($count <= 0) {
            return;
        }

        $id = (int) $id;
        if (!isset($stats['top'][$type]) || !is_array($stats['top'][$type])) {
            $stats['top'][$type] = [];
        }

        $found = false;
        foreach ($stats['top'][$type] as $idx => $row) {
            $row = is_object($row) ? (array) $row : (array) $row;
            if ((int) ($row[$type] ?? 0) == $id) {
                $stats['top'][$type][$idx]['kills'] = $count;
                $stats['top'][$type][$idx]['isk'] = (double) ($summary['isk'] ?? 0);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $stats['top'][$type][] = [
                $type => $id,
                'kills' => $count,
                'isk' => (double) ($summary['isk'] ?? 0),
            ];
        }
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

    private static function hydrateForView(&$doc, $viewSides = ['kills', 'losses'])
    {
        foreach ($viewSides as $sideName) {
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

    private static function selectedDaysFromJob($job)
    {
        $days = [];
        $input = (string) ($job['days'] ?? '');
        if ($input != '' && $input != 'all') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $input, $matches) && $matches[1] <= $matches[2]) {
                $cursor = new DateTimeImmutable($matches[1], new DateTimeZone('UTC'));
                $end = new DateTimeImmutable($matches[2], new DateTimeZone('UTC'));
                while ($cursor <= $end) {
                    $days[] = $cursor->format('Y-m-d');
                    $cursor = $cursor->modify('+1 day');
                }
            } else {
                foreach (explode(',', $input) as $day) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $days[$day] = $day;
                }
                $days = array_values($days);
            }
        } else if (isset($job['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $job['date'])) {
            $days[] = (string) $job['date'];
        }
        sort($days);
        return $days;
    }

    private static function buildQueryForSelectedDays($type, $id, $days, $losses)
    {
        if (count($days) > 0) return self::buildQueryForDays($type, $id, $days, $losses);
        $parameters = self::buildParameters($type, $id, null, $losses);
        return MongoFilter::buildQuery($parameters);
    }

    private static function summaryOnDemand($type, $id, $days, $losses)
    {
        global $mdb;

        $query = self::buildQueryForSelectedDays($type, $id, $days, $losses);
        $rows = iterator_to_array($mdb->getCollection('killmails')->aggregate([
            ['$match' => $query],
            ['$group' => [
                '_id' => null,
                'count' => ['$sum' => 1],
                'isk' => ['$sum' => '$zkb.totalValue'],
                'points' => ['$sum' => '$zkb.points'],
            ]],
            ['$project' => ['_id' => 0, 'count' => 1, 'isk' => 1, 'points' => 1]],
        ], ['allowDiskUse' => true]));
        $row = $rows[0] ?? [];
        return [
            'count' => (int) ($row['count'] ?? 0),
            'isk' => (double) ($row['isk'] ?? 0),
            'points' => (int) ($row['points'] ?? 0),
        ];
    }

    private static function topValuesOnDemand($type, $id, $days, $losses)
    {
        global $mdb;

        if (count($days) == 1) {
            $parameters = self::buildParameters($type, $id, $days[0], $losses);
            $parameters['limit'] = 6;
            return Stats::getTopIsk($parameters);
        }

        $query = self::buildQueryForSelectedDays($type, $id, $days, $losses);
        $rows = $mdb->getCollection('killmails')->find($query, [
            'projection' => ['_id' => 0, 'killID' => 1],
            'sort' => ['zkb.totalValue' => -1, 'killID' => -1],
            'limit' => 6,
        ])->toArray();
        return Kills::getDetails(array_values(array_map(function ($row) { return (int) ($row['killID'] ?? 0); }, $rows)), true);
    }

    private static function topListsOnDemand($type, $id, $days, $losses)
    {
        $query = self::buildQueryForSelectedDays($type, $id, $days, $losses);
        $topLists = [];
        foreach (self::$topTypes as $group => $meta) {
            $topLists[] = [
                'type' => $meta['label'] ?? $group,
                'typeID' => $group,
                'data' => AdvancedSearch::getTop($group, $query, $losses ? 'true' : 'false', [], true, 'killID', -1),
            ];
        }
        return $topLists;
    }

    private static function entityTypeFromStatType($type)
    {
        $map = [
            'characterID' => 'character',
            'corporationID' => 'corporation',
            'allianceID' => 'alliance',
            'factionID' => 'faction',
            'shipTypeID' => 'ship',
            'groupID' => 'group',
            'solarSystemID' => 'system',
            'constellationID' => 'constellation',
            'regionID' => 'region',
            'locationID' => 'location',
            'label' => 'label',
        ];
        return $map[$type] ?? $type;
    }

}
