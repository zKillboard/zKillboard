<?php

class DailyStats
{
    const COLLECTION = 'stats_monthly';
    const MONTH_FIELD = 'yyyymm';
    const PERSIST_MIN_TOTAL = 10000;
    const VERSION = 2;

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
        $types = [
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
        return $types[$type] ?? $type;
    }

    public static function hasData($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $stats = $mdb->getCollection('statistics')->findOne(
            ['type' => $type, 'id' => $id],
            ['projection' => ['shipsDestroyed' => 1, 'shipsLost' => 1]]
        );
        return (int) ($stats['shipsDestroyed'] ?? 0) + (int) ($stats['shipsLost'] ?? 0) > 0;
    }

    public static function shouldPersist($type, $id)
    {
        global $mdb;

        $type = self::normalizeType($type);
        $id = $type == 'label' ? (string) $id : (int) $id;
        $stats = $mdb->getCollection('statistics')->findOne(
            ['type' => $type, 'id' => $id],
            ['projection' => ['shipsDestroyed' => 1, 'shipsLost' => 1]]
        );
        return (int) ($stats['shipsDestroyed'] ?? 0) + (int) ($stats['shipsLost'] ?? 0) >= self::PERSIST_MIN_TOTAL;
    }

    public static function queueBackfill($type, $id, $stats = null)
    {
        global $mdb, $redis;

        $type = self::normalizeType($type);
        if (!isset(self::$types[$type])) return 0;

        $id = $type == 'label' ? (string) $id : (int) $id;
        if ($id === '' || ($type != 'label' && $id == 0)) return 0;

        if ($stats == null) {
            $stats = $mdb->getCollection('statistics')->findOne(
                ['type' => $type, 'id' => $id],
                ['projection' => ['shipsDestroyed' => 1, 'shipsLost' => 1, 'months' => 1]]
            );
        }
        $stats = is_object($stats) ? (array) $stats : (array) $stats;
        if ((int) ($stats['shipsDestroyed'] ?? 0) + (int) ($stats['shipsLost'] ?? 0) < self::PERSIST_MIN_TOTAL) return 0;

        $redis->hset('zkb:stats_monthly:qualified', "$type:$id", true);
        $existing = [];
        foreach ($mdb->getCollection(self::COLLECTION)->find(
            ['type' => $type, 'id' => $id],
            ['projection' => [self::MONTH_FIELD => 1, 'complete' => 1, 'version' => 1]]
        ) as $row) {
            $existing[(string) ($row[self::MONTH_FIELD] ?? '')] = !empty($row['complete']) && (int) ($row['version'] ?? 0) >= self::VERSION;
        }

        $ops = [];
        $queued = 0;
        foreach ((array) ($stats['months'] ?? []) as $yyyymm => $monthStats) {
            $yyyymm = (string) $yyyymm;
            $monthStats = is_object($monthStats) ? (array) $monthStats : (array) $monthStats;
            if (!preg_match('/^(\d{4})(\d{2})$/', $yyyymm, $matches) || !checkdate((int) $matches[2], 1, (int) $matches[1])) continue;
            if ((int) ($monthStats['shipsDestroyed'] ?? 0) + (int) ($monthStats['shipsLost'] ?? 0) == 0) continue;
            if (!empty($existing[$yyyymm])) continue;

            $updates = [];
            $days = (int) gmdate('t', strtotime($matches[1] . '-' . $matches[2] . '-01 UTC'));
            if ($yyyymm > gmdate('Ym')) continue;
            if ($yyyymm == gmdate('Ym')) $days = min($days, (int) gmdate('d'));
            for ($day = 1; $day <= $days; $day++) {
                $updates[] = $yyyymm . sprintf('%02d', $day) . ':-1';
            }
            $key = ['type' => $type, 'id' => $id, self::MONTH_FIELD => $yyyymm];
            $ops[] = ['updateOne' => [
                $key,
                ['$setOnInsert' => $key, '$addToSet' => ['updates' => ['$each' => $updates]]],
                ['upsert' => true],
            ]];
            $queued += count($updates);
        }

        if (count($ops) > 0) $mdb->getCollection(self::COLLECTION)->bulkWrite($ops, ['ordered' => false]);
        return $queued;
    }

    public static function rebuildMonthly($row)
    {
        global $mdb;

        $type = self::normalizeType($row['type'] ?? '');
        $id = $type == 'label' ? (string) ($row['id'] ?? '') : (int) ($row['id'] ?? 0);
        $yyyymm = (string) ($row[self::MONTH_FIELD] ?? '');
        if (!isset(self::$types[$type]) || $id === '' || ($type != 'label' && $id == 0) || !preg_match('/^\d{6}$/', $yyyymm)) {
            throw new Exception('Invalid daily stats record');
        }

        $updates = [];
        $hadBackfill = false;
        foreach ((array) ($row['updates'] ?? []) as $update) {
            if (!preg_match('/^(\d{8}):(-1|\d+)$/', (string) $update, $matches) || substr($matches[1], 0, 6) != $yyyymm) continue;
            $date = $matches[1];
            $sequence = (int) $matches[2];
            $hadBackfill = $hadBackfill || $sequence == -1;
            if (!isset($updates[$date]) || $updates[$date] < $sequence) $updates[$date] = $sequence;
        }

        $set = ['updated' => time()];
        $unset = ['months' => 1, 'yyyy-mm' => 1];
        $dates = array_map(function ($date) {
            return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        }, array_keys($updates));
        $maxTimeMS = $hadBackfill ? null : 25000;
        $filter = self::entityFilter($type, $id);
        $daily = ['kills' => [], 'losses' => []];
        foreach (array_chunk($dates, 7) as $chunk) {
            $daily['kills'] += AdvancedSearch::getDailyFacets(self::buildQuery($type, $id, $chunk, false), false, $filter, self::$topTypes, 'killmails', $maxTimeMS, true);
            $daily['losses'] += AdvancedSearch::getDailyFacets(self::buildQuery($type, $id, $chunk, true), true, $filter, self::$topTypes, 'killmails', $maxTimeMS, true);
        }
        $emptySide = [
            'summary' => ['count' => 0, 'isk' => 0, 'points' => 0],
            'labels' => [],
            'top' => array_fill_keys(array_keys(self::$topTypes), []),
            'topValueKillIDs' => [],
        ];
        foreach ($updates as $date => $sequence) {
            $currentSequence = (int) ($row[$date]['sequence'] ?? 0);
            $currentVersion = (int) ($row[$date]['version'] ?? 0);
            if ($currentVersion >= self::VERSION && (($sequence == -1 && isset($row[$date])) || ($sequence >= 0 && $currentSequence >= $sequence))) continue;

            $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            $kills = $daily['kills'][$day] ?? $emptySide;
            $losses = $daily['losses'][$day] ?? $emptySide;
            if ($kills['summary']['count'] == 0 && $losses['summary']['count'] == 0) {
                $unset[$date] = 1;
                continue;
            }

            $set[$date] = [
                'day' => $day,
                'sequence' => max(0, $sequence),
                'version' => self::VERSION,
                'kills' => $kills,
                'losses' => $losses,
            ];
        }
        if ($hadBackfill) {
            $set['complete'] = true;
            $set['version'] = self::VERSION;
        }

        $update = ['$set' => $set, '$unset' => $unset];
        if (count((array) ($row['updates'] ?? [])) > 0) $update['$pullAll'] = ['updates' => array_values((array) $row['updates'])];
        $mdb->getCollection(self::COLLECTION)->updateOne(['_id' => $row['_id']], $update);
        $mdb->getCollection(self::COLLECTION)->updateOne(
            ['_id' => $row['_id'], 'updates' => []],
            ['$unset' => ['updates' => 1]]
        );

        return count($updates);
    }

    public static function runQueuedQuery($job)
    {
        $type = self::normalizeType($job['type'] ?? '');
        $id = $type == 'label' ? (string) ($job['id'] ?? '') : (int) ($job['id'] ?? 0);
        $side = in_array(($job['side'] ?? 'kills'), ['kills', 'losses']) ? $job['side'] : 'kills';
        $part = in_array(($job['part'] ?? 'history'), ['history', 'summary', 'topvalues', 'toplists', 'labels']) ? $job['part'] : 'history';
        $group = isset(self::$topTypes[$job['group'] ?? '']) ? (string) $job['group'] : '';
        $selectedDays = self::selectedDays($job);
        $selectionAll = count($selectedDays) == 0;
        $dailyDays = [];
        $dailyStats = [
            'type' => $type,
            'id' => $id,
            'day' => count($selectedDays) == 1 ? $selectedDays[0] : null,
            'days' => $selectedDays,
            'kills' => self::emptySide(),
            'losses' => self::emptySide(),
        ];

        if ($part == 'history') {
            $month = self::selectedMonth($selectedDays, $job);
            $dailyDays = $month == null ? self::getMonths($type, $id) : self::getMonth($type, $id, $month);
            $dailyStats['days'] = array_values(array_map(function ($row) { return $row['day']; }, $dailyDays));
        } else {
            $saved = self::getSavedPart($type, $id, $selectedDays, $side, $part, $group);
            $query = $saved === null ? self::buildQuery($type, $id, $selectedDays, $side == 'losses') : [];
            if ($part == 'summary') {
                $dailyStats[$side]['summary'] = $saved ?? self::getSummary($type, $query);
            } else if ($part == 'topvalues') {
                if ($saved !== null) {
                    $saved = AdvancedSearch::getKillIDs(['killID' => ['$in' => $saved]], ['zkb.totalValue' => -1, 'killID' => -1], 6);
                }
                $killIDs = $saved ?? AdvancedSearch::getKillIDs($query, ['zkb.totalValue' => -1, 'killID' => -1], 6);
                $dailyStats[$side]['topValues'] = Kills::getDetails($killIDs, true);
            } else if ($part == 'toplists' && $group != '') {
                $top = $saved;
                if ($top === null) {
                    $top = AdvancedSearch::getTop(
                        $group,
                        $query,
                        $side == 'losses' ? 'true' : 'false',
                        self::entityFilter($type, $id),
                        true,
                        'killID',
                        -1
                    );
                } else {
                    Info::addInfo($top);
                }
                $dailyStats[$side]['topLists'] = [[
                    'type' => self::$topTypes[$group]['label'],
                    'typeID' => $group,
                    'data' => $top,
                ]];
            } else if ($part == 'labels') {
                $dailyStats[$side]['labels'] = $saved ?? AdvancedSearch::getLabelStats($query);
            }
        }

        $start = count($selectedDays) > 0 ? min($selectedDays) : null;
        $end = count($selectedDays) > 0 ? max($selectedDays) : null;
        if ($part == 'history' && count($dailyDays) > 0) {
            $start = $start ?? min(array_column($dailyDays, 'day'));
            $periodEnds = array_map(function ($row) { return $row['periodEnd'] ?? $row['day']; }, $dailyDays);
            $end = $end ?? max($periodEnds);
        }

        return [
            'dailyStats' => $dailyStats,
            'dailyDays' => $dailyDays,
            'dailySide' => $side,
            'dailySelectedDays' => $selectedDays,
            'dailySelectedStart' => $start,
            'dailySelectedEnd' => $end,
            'dailySelectionAll' => $selectionAll,
            'dailyGraphStart' => null,
            'dailyGraphEnd' => null,
            'dailyDate' => count($selectedDays) == 1 ? $selectedDays[0] : null,
            'entityType' => $job['entityType'] ?? self::entityType($type),
            'entityID' => $id,
            'key' => $job['entityType'] ?? self::entityType($type),
            'id' => $id,
        ];
    }

    private static function getMonth($type, $id, $yyyymm, $maxTimeMS = 25000, $cacheOverride = false)
    {
        global $mdb;

        $row = $mdb->getCollection(self::COLLECTION)->findOne([
            'type' => $type,
            'id' => $id,
            self::MONTH_FIELD => str_replace('-', '', $yyyymm),
        ]);
        $days = [];
        if ($row != null && !isset($row['updates']) && !empty($row['complete'])) {
            foreach ($row as $date => $day) {
                if (!preg_match('/^\d{8}$/', (string) $date)) continue;
                $day = is_object($day) ? (array) $day : (array) $day;
                if (isset($day['day'])) $days[$day['day']] = $day;
            }
        } else {
            $start = $yyyymm . '-01';
            $end = gmdate('Y-m-t', strtotime($start . ' UTC'));
            $dates = [];
            $cursor = new DateTimeImmutable($start, new DateTimeZone('UTC'));
            $last = new DateTimeImmutable($end, new DateTimeZone('UTC'));
            while ($cursor <= $last) {
                $dates[] = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('+1 day');
            }

            $killQuery = self::buildQuery($type, $id, $dates, false);
            $lossQuery = self::buildQuery($type, $id, $dates, true);
            $summaries = [
                'kills' => AdvancedSearch::getDailySums($killQuery, 'killmails', $maxTimeMS, $cacheOverride, $cacheOverride),
                'losses' => $killQuery == $lossQuery ? null : AdvancedSearch::getDailySums($lossQuery, 'killmails', $maxTimeMS, $cacheOverride, $cacheOverride),
            ];
            if ($summaries['losses'] === null) $summaries['losses'] = $summaries['kills'];
            foreach ($summaries as $side => $rows) {
                foreach ($rows as $summary) {
                    $summary = is_object($summary) ? (array) $summary : (array) $summary;
                    $day = (string) ($summary['day'] ?? '');
                    if ($day == '') continue;
                    if (!isset($days[$day])) $days[$day] = ['day' => $day, 'kills' => self::emptySide(), 'losses' => self::emptySide()];
                    $days[$day][$side]['summary'] = [
                        'count' => (int) ($summary['count'] ?? 0),
                        'isk' => (double) ($summary['isk'] ?? 0),
                        'points' => (int) ($summary['points'] ?? 0),
                    ];
                }
            }
        }

        $start = $yyyymm . '-01';
        $end = gmdate('Y-m-t', strtotime($start . ' UTC'));
        foreach ([$start, $end] as $day) {
            if (!isset($days[$day])) $days[$day] = ['day' => $day, 'kills' => self::emptySide(), 'losses' => self::emptySide()];
        }
        ksort($days);
        return array_values($days);
    }

    private static function getMonths($type, $id)
    {
        global $mdb;

        $stats = $mdb->getCollection('statistics')->findOne(
            ['type' => $type, 'id' => $id],
            ['projection' => ['months' => 1]]
        );
        $months = [];
        foreach ((array) ($stats['months'] ?? []) as $yyyymm => $row) {
            $yyyymm = (string) $yyyymm;
            $row = is_object($row) ? (array) $row : (array) $row;
            if (!preg_match('/^(\d{4})(\d{2})$/', $yyyymm, $matches) || !checkdate((int) $matches[2], 1, (int) $matches[1])) continue;
            $day = $matches[1] . '-' . $matches[2] . '-01';
            $months[$day] = [
                'day' => $day,
                'periodEnd' => gmdate('Y-m-t', strtotime($day . ' UTC')),
                'kills' => ['summary' => [
                    'count' => (int) ($row['shipsDestroyed'] ?? 0),
                    'isk' => (double) ($row['iskDestroyed'] ?? 0),
                    'points' => (int) ($row['pointsDestroyed'] ?? 0),
                ]],
                'losses' => ['summary' => [
                    'count' => (int) ($row['shipsLost'] ?? 0),
                    'isk' => (double) ($row['iskLost'] ?? 0),
                    'points' => (int) ($row['pointsLost'] ?? 0),
                ]],
            ];
        }
        ksort($months);
        return array_values($months);
    }

    private static function getSavedPart($type, $id, $days, $side, $part, $group)
    {
        global $mdb;

        $months = [];
        if (count($days) > 0) {
            foreach ($days as $day) {
                $yyyymm = str_replace('-', '', substr($day, 0, 7));
                $months[$yyyymm] = $yyyymm;
            }
        } else {
            $stats = $mdb->getCollection('statistics')->findOne(
                ['type' => $type, 'id' => $id],
                ['projection' => ['months' => 1]]
            );
            foreach ((array) ($stats['months'] ?? []) as $yyyymm => $row) {
                $row = is_object($row) ? (array) $row : (array) $row;
                if (!preg_match('/^\d{6}$/', (string) $yyyymm)) continue;
                if ((int) ($row['shipsDestroyed'] ?? 0) + (int) ($row['shipsLost'] ?? 0) > 0) $months[(string) $yyyymm] = (string) $yyyymm;
            }
        }
        if (count($months) == 0) return null;

        $path = $part;
        if ($part == 'topvalues') $path = 'topValueKillIDs';
        else if ($part == 'toplists') $path = "top.$group";
        $valuePath = '$$field.v.' . $side . '.' . $path;
        $rows = $mdb->getCollection(self::COLLECTION)->aggregate([
            ['$match' => ['type' => $type, 'id' => $id, self::MONTH_FIELD => ['$in' => array_values($months)]]],
            ['$project' => [
                '_id' => 0,
                self::MONTH_FIELD => 1,
                'complete' => 1,
                'version' => 1,
                'updates' => 1,
                'days' => ['$map' => [
                    'input' => ['$filter' => [
                        'input' => ['$objectToArray' => '$$ROOT'],
                        'as' => 'field',
                        'cond' => ['$regexMatch' => ['input' => '$$field.k', 'regex' => '^\d{8}$']],
                    ]],
                    'as' => 'field',
                    'in' => [
                        'date' => '$$field.k',
                        'version' => '$$field.v.version',
                        'value' => ['$ifNull' => [$valuePath, []]],
                    ],
                ]],
            ]],
        ]);

        $docs = [];
        foreach ($rows as $row) $docs[(string) ($row[self::MONTH_FIELD] ?? '')] = is_object($row) ? (array) $row : (array) $row;
        if (count($docs) != count($months)) return null;

        $summary = ['count' => 0, 'isk' => 0, 'points' => 0];
        $labels = [];
        $top = [];
        $killIDs = [];
        foreach (array_values($months) as $yyyymm) {
            $doc = $docs[$yyyymm] ?? null;
            if ($doc == null || isset($doc['updates'])) return null;

            $complete = !empty($doc['complete']) && (int) ($doc['version'] ?? 0) >= self::VERSION;
            if (count($days) == 0 && !$complete) return null;
            $savedDays = [];
            foreach ((array) ($doc['days'] ?? []) as $day) {
                $day = is_object($day) ? (array) $day : (array) $day;
                $date = (string) ($day['date'] ?? '');
                if ($date != '') $savedDays[$date] = $day;
            }

            $wanted = count($days) == 0 ? array_keys($savedDays) : array_values(array_filter($days, function ($day) use ($yyyymm) {
                return str_replace('-', '', substr($day, 0, 7)) == $yyyymm;
            }));
            foreach ($wanted as $day) {
                $date = str_replace('-', '', $day);
                if (!isset($savedDays[$date])) {
                    if ($complete) continue;
                    return null;
                }
                if ((int) ($savedDays[$date]['version'] ?? 0) < self::VERSION) return null;
                $value = $savedDays[$date]['value'] ?? [];

                if ($part == 'summary') {
                    $value = is_object($value) ? (array) $value : (array) $value;
                    $summary['count'] += (int) ($value['count'] ?? 0);
                    $summary['isk'] += (double) ($value['isk'] ?? 0);
                    $summary['points'] += (int) ($value['points'] ?? 0);
                } else if ($part == 'labels') {
                    foreach ((array) $value as $label) {
                        $label = is_object($label) ? (array) $label : (array) $label;
                        $name = (string) ($label['label'] ?? '');
                        if ($name == '') continue;
                        if (!isset($labels[$name])) $labels[$name] = ['label' => $name, 'count' => 0, 'isk' => 0];
                        $labels[$name]['count'] += (int) ($label['count'] ?? 0);
                        $labels[$name]['isk'] += (double) ($label['isk'] ?? 0);
                    }
                } else if ($part == 'topvalues') {
                    foreach ((array) $value as $killID) {
                        $killID = (int) $killID;
                        if ($killID > 0) $killIDs[$killID] = $killID;
                    }
                } else if ($part == 'toplists') {
                    foreach ((array) $value as $row) {
                        $row = is_object($row) ? (array) $row : (array) $row;
                        $entityID = (int) ($row[$group] ?? 0);
                        if ($entityID == 0) continue;
                        if (!isset($top[$entityID])) $top[$entityID] = [$group => $entityID, 'kills' => 0, 'isk' => 0];
                        $top[$entityID]['kills'] += (int) ($row['kills'] ?? 0);
                        $top[$entityID]['isk'] += (double) ($row['isk'] ?? 0);
                    }
                }
            }
        }

        if ($part == 'summary') return $summary;
        if ($part == 'topvalues') return array_values($killIDs);
        $result = array_values($part == 'labels' ? $labels : $top);
        usort($result, function ($a, $b) {
            $aCount = (int) ($a['count'] ?? $a['kills'] ?? 0);
            $bCount = (int) ($b['count'] ?? $b['kills'] ?? 0);
            if ($aCount == $bCount) return (double) ($b['isk'] ?? 0) <=> (double) ($a['isk'] ?? 0);
            return $bCount <=> $aCount;
        });
        return $part == 'toplists' ? array_slice($result, 0, 50) : $result;
    }

    private static function getSummary($type, $query, $cacheOverride = false)
    {
        $row = AdvancedSearch::getSums($type, $query, 'null', $cacheOverride, false, 'killmails', $cacheOverride ? null : 25000, $cacheOverride);
        if (!empty($row['timedOut'])) throw new Exception('Daily stats query timed out', 50);
        return [
            'count' => (int) ($row['kills'] ?? 0),
            'isk' => (double) ($row['isk'] ?? 0),
            'points' => (int) ($row['points'] ?? 0),
        ];
    }

    private static function buildQuery($type, $id, $days, $losses)
    {
        $parameters = [];
        if ($type == 'label') {
            if ($id != 'all') $parameters['labels'] = $id;
        } else {
            $parameters[$type] = $id;
            if (self::$types[$type]) $parameters[$losses ? 'losses' : 'kills'] = true;
            $parameters['npc'] = false;
            $parameters['labels'] = 'pvp';
        }
        $query = MongoFilter::buildQuery($parameters);
        if (count($days) == 0) return $query;

        $ranges = [];
        $start = min($days);
        $end = max($days);
        $expected = (new DateTimeImmutable($start, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($end, new DateTimeZone('UTC')))->days + 1;
        $dateRanges = $expected == count($days) ? [[$start, $end]] : array_map(function ($day) { return [$day, $day]; }, $days);
        foreach ($dateRanges as $range) {
            [$startYear, $startMonth, $startDay] = array_map('intval', explode('-', $range[0]));
            [$endYear, $endMonth, $endDay] = array_map('intval', explode('-', $range[1]));
            $ranges[] = ['killID' => [
                '$gte' => MongoFilter::getFirstKillID($startYear, $startMonth, $startDay),
                '$lte' => MongoFilter::getLastKillID($endYear, $endMonth, $endDay),
            ]];
        }
        $dateQuery = count($ranges) == 1 ? $ranges[0] : ['$or' => $ranges];
        if (count($query) == 0) return $dateQuery;
        if (isset($query['$and'])) {
            $query['$and'][] = $dateQuery;
            return $query;
        }
        return ['$and' => [$query, $dateQuery]];
    }

    private static function selectedDays($job)
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

    private static function selectedMonth($selectedDays, $job)
    {
        $ranges = count($selectedDays) > 0 ? [[min($selectedDays), max($selectedDays)]] : [];
        foreach (['graph', 'days'] as $field) {
            $value = (string) ($job[$field] ?? '');
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $value, $matches)) $ranges[] = [$matches[1], $matches[2]];
            else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) $ranges[] = [$value, $value];
        }
        foreach ($ranges as $range) {
            if (substr($range[0], 0, 7) == substr($range[1], 0, 7)) return substr($range[0], 0, 7);
        }
        return null;
    }

    private static function entityFilter($type, $id)
    {
        if (!isset(self::$topTypes[$type]) || strpos(self::$topTypes[$type]['field'], 'involved.') !== 0) return [];
        return [self::$topTypes[$type]['field'] => (int) $id];
    }

    private static function emptySide()
    {
        return ['summary' => ['count' => 0, 'isk' => 0, 'points' => 0], 'labels' => [], 'topValues' => [], 'topLists' => []];
    }

    private static function entityType($type)
    {
        $types = [
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
        return $types[$type] ?? $type;
    }
}
