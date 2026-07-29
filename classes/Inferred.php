<?php

class Inferred
{
    public static function victim($mail)
    {
        foreach ((array) ($mail['involved'] ?? []) as $involved) {
            if (($involved['isVictim'] ?? false) === true) return $involved;
        }
        return $mail['involved'][0] ?? [];
    }

    public static function mailTime($mail)
    {
        $dttm = $mail['dttm'] ?? null;
        if ($dttm instanceof MongoDB\BSON\UTCDateTime) return $dttm->toDateTime()->getTimestamp();
        if (is_string($dttm)) return strtotime($dttm);
        if (isset($mail['killTime'])) return strtotime($mail['killTime']);
        return 0;
    }

    public static function attackerCount($mail)
    {
        if ((int) ($mail['attackerCount'] ?? 0) > 0) return (int) $mail['attackerCount'];

        $count = 0;
        foreach ((array) ($mail['involved'] ?? []) as $involved) {
            if (($involved['isVictim'] ?? null) === false && (int) ($involved['characterID'] ?? 0) > 0) $count++;
        }
        return max(1, $count);
    }

    public static function isShip($shipTypeID)
    {
        static $shipCache = [];

        if (!isset($shipCache[$shipTypeID])) {
            $shipCache[$shipTypeID] = ((int) Info::getInfoField('typeID', $shipTypeID, 'categoryID') == 6);
        }

        return $shipCache[$shipTypeID];
    }

    public static function getPopularPveLosses($query, $maxTimeMS = 25000)
    {
        global $mdb;

        $pipeline = [
            ['$match' => (empty($query) ? new stdClass() : $query)],
            ['$unwind' => '$involved'],
            ['$match' => [
                'involved.isVictim' => true,
                'involved.shipTypeID' => ['$gt' => 0],
            ]],
            ['$group' => [
                '_id' => '$involved.shipTypeID',
                'losses' => ['$sum' => 1],
                'avgCost' => ['$avg' => '$zkb.totalValue'],
                'sampleKillID' => ['$max' => '$killID'],
            ]],
            ['$sort' => ['losses' => -1, 'avgCost' => -1]],
            ['$limit' => 5],
            ['$project' => [
                '_id' => 0,
                'shipTypeID' => '$_id',
                'losses' => 1,
                'avgCost' => 1,
                'sampleKillID' => 1,
            ]],
        ];
        $options = ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true];
        if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;

        $rows = iterator_to_array($mdb->getCollection('ninetyDays')->aggregate($pipeline, $options));
        foreach ($rows as $index => &$row) {
            $shipTypeID = (int) ($row['shipTypeID'] ?? 0);
            $row['displayRank'] = $index + 1;
            $row['shipName'] = Info::getInfoField('typeID', $shipTypeID, 'name');
            $row['pip'] = Info::getInfoField('typeID', $shipTypeID, 'pip');
            $row['losses'] = (int) ($row['losses'] ?? 0);
            $row['avgCost'] = round((float) ($row['avgCost'] ?? 0), 2);
            $row['sampleKillID'] = (int) ($row['sampleKillID'] ?? 0);
        }
        unset($row);

        return ['mode' => 'npcLosses', 'losses' => $rows, 'windowDays' => 90];
    }

    public static function getAdvancedSearchFits($query, $shipTypeID, $maxTimeMS = 25000)
    {
        global $mdb, $redis;

        $shipName = Info::getInfoField('typeID', $shipTypeID, 'name');
        $pip = Info::getInfoField('typeID', $shipTypeID, 'pip');
        $killmails = $mdb->getCollection('ninetyDays');
        $pipeline = [
            ['$match' => (empty($query) ? new stdClass() : $query)],
            ['$unwind' => '$involved'],
            ['$match' => [
                'involved.isVictim' => false,
                'involved.shipTypeID' => $shipTypeID,
                'involved.characterID' => ['$gt' => 0],
            ]],
            ['$project' => [
                '_id' => 0,
                'killID' => 1,
                'dttm' => 1,
                'characterID' => '$involved.characterID',
                'finalBlow' => '$involved.finalBlow',
                'solo' => 1,
                'attackerCount' => 1,
                'totalValue' => '$zkb.totalValue',
            ]],
            ['$sort' => ['dttm' => -1, 'killID' => -1]],
        ];
        $options = ['cursor' => ['batchSize' => 1000], 'allowDiskUse' => true];
        if ($maxTimeMS !== null) $options['maxTimeMS'] = $maxTimeMS;

        $killRows = iterator_to_array($killmails->aggregate($pipeline, $options));
        if (sizeof($killRows) == 0) {
            return ['fits' => [], 'windowDays' => 90, 'shipTypeID' => $shipTypeID, 'shipName' => $shipName, 'message' => "No matching attacker kills found for $shipName in the last 90 days."];
        }

        $characterIDs = [];
        foreach ($killRows as $killRow) $characterIDs[(int) ($killRow['characterID'] ?? 0)] = (int) ($killRow['characterID'] ?? 0);
        unset($characterIDs[0]);
        $characterIDs = array_values($characterIDs);

        $lossOptions = [
            'projection' => ['_id' => 0, 'killID' => 1, 'dttm' => 1, 'involved' => 1, 'zkb.totalValue' => 1],
            'sort' => ['dttm' => -1, 'killID' => -1],
        ];
        if ($maxTimeMS !== null) $lossOptions['maxTimeMS'] = $maxTimeMS;
        $lossRows = iterator_to_array($killmails->find(
            [
                'dttm' => ['$gte' => new MongoDB\BSON\UTCDateTime((time() - (90 * 86400)) * 1000)],
                'involved' => ['$elemMatch' => [
                    'characterID' => ['$in' => $characterIDs],
                    'shipTypeID' => $shipTypeID,
                    'isVictim' => true,
                ]],
            ],
            $lossOptions
        ));
        if (sizeof($lossRows) == 0) return ['fits' => [], 'windowDays' => 90, 'shipTypeID' => $shipTypeID, 'shipName' => $shipName, 'message' => "No inferred fit losses found for matching $shipName pilots in the last 90 days."];

        $lossIDs = [];
        foreach ($lossRows as $lossRow) $lossIDs[] = (int) ($lossRow['killID'] ?? 0);
        $esiOptions = ['projection' => ['_id' => 0, 'killmail_id' => 1, 'victim.items' => 1, 'victim.ship_type_id' => 1]];
        if ($maxTimeMS !== null) $esiOptions['maxTimeMS'] = $maxTimeMS;
        $esiRows = iterator_to_array($mdb->getCollection('esimails')->find(['killmail_id' => ['$in' => $lossIDs]], $esiOptions));
        $esiByKillID = [];
        foreach ($esiRows as $esiRow) $esiByKillID[(int) ($esiRow['killmail_id'] ?? 0)] = $esiRow;

        $events = [];
        $fitCosts = [];
        foreach ($killRows as $killRow) {
            $events[] = [
                'type' => 'kill',
                'killID' => (int) ($killRow['killID'] ?? 0),
                'time' => self::mailTime($killRow),
                'characterID' => (int) ($killRow['characterID'] ?? 0),
                'finalBlow' => ($killRow['finalBlow'] ?? false) === true,
                'solo' => ($killRow['solo'] ?? false) === true || (int) ($killRow['attackerCount'] ?? 0) == 1,
                'totalValue' => (float) ($killRow['totalValue'] ?? 0),
            ];
        }
        foreach ($lossRows as $lossRow) {
            $killID = (int) ($lossRow['killID'] ?? 0);
            $esimail = $esiByKillID[$killID] ?? null;
            if ($esimail == null || (int) ($esimail['victim']['ship_type_id'] ?? 0) != $shipTypeID) continue;
            $signature = self::signature($shipTypeID, $esimail['victim']['items'] ?? []);
            $hash = $signature['hash'] ?? null;
            if ($hash == null) continue;
            $victim = self::victim($lossRow);
            $characterID = (int) ($victim['characterID'] ?? 0);
            if ($characterID <= 0) continue;
            if (!isset($fitCosts[$hash])) $fitCosts[$hash] = ['sum' => 0, 'count' => 0];
            $fitCosts[$hash]['sum'] += (float) ($lossRow['zkb']['totalValue'] ?? 0);
            $fitCosts[$hash]['count']++;
            $events[] = [
                'type' => 'loss',
                'killID' => $killID,
                'time' => self::mailTime($lossRow),
                'characterID' => $characterID,
                'hash' => $hash,
            ];
        }

        usort($events, function ($a, $b) {
            if ($a['time'] == $b['time']) return ($b['killID'] ?? 0) <=> ($a['killID'] ?? 0);
            return ($b['time'] ?? 0) <=> ($a['time'] ?? 0);
        });

        $active = [];
        $stats = [];
        foreach ($events as $event) {
            $characterID = (int) ($event['characterID'] ?? 0);
            if ($event['type'] == 'loss') {
                $active[$characterID] = ['hash' => $event['hash'], 'sampleLossID' => $event['killID']];
                continue;
            }
            if (!isset($active[$characterID])) continue;

            $hash = $active[$characterID]['hash'];
            if (!isset($stats[$hash])) {
                $stats[$hash] = [
                    'hash' => $hash,
                    'kills' => 0,
                    'finalBlows' => 0,
                    'soloKills' => 0,
                    'iskDestroyed' => 0,
                    'sampleLossID' => $active[$characterID]['sampleLossID'],
                ];
            }
            $stats[$hash]['kills']++;
            if ($event['finalBlow']) $stats[$hash]['finalBlows']++;
            if ($event['solo']) $stats[$hash]['soloKills']++;
            $stats[$hash]['iskDestroyed'] += (float) ($event['totalValue'] ?? 0);
        }
        if (sizeof($stats) == 0) return ['fits' => [], 'windowDays' => 90, 'shipTypeID' => $shipTypeID, 'shipName' => $shipName, 'message' => "No inferred fits found for matching $shipName kills in the last 90 days."];

        uasort($stats, function ($a, $b) {
            if ($a['kills'] == $b['kills']) return $b['finalBlows'] <=> $a['finalBlows'];
            return $b['kills'] <=> $a['kills'];
        });
        $stats = array_slice($stats, 0, 5, true);

        $runID = $redis->get('zkb:fitKillers:runID');
        if ($runID == null) {
            $latest = $mdb->findDoc('fitkillers', [], ['updated' => -1], ['runID' => 1]);
            $runID = $latest['runID'] ?? null;
        }

        $fitQuery = ['hash' => ['$in' => array_keys($stats)]];
        if ($runID != null) $fitQuery['runID'] = $runID;
        $fitDocs = $mdb->find('fitkillers', $fitQuery, [], null, ['_id' => 0]);
        $fitDocsByHash = [];
        foreach ($fitDocs as $fitDoc) $fitDocsByHash[$fitDoc['hash']] = $fitDoc;

        $fits = [];
        foreach ($stats as $hash => $stat) {
            if (!isset($fitDocsByHash[$hash])) continue;
            $fit = $fitDocsByHash[$hash];
            $fit['matchedKills'] = (int) $stat['kills'];
            $fit['kills'] = (int) $stat['kills'];
            $fit['finalBlows'] = (int) $stat['finalBlows'];
            $fit['soloKills'] = (int) $stat['soloKills'];
            $fit['iskDestroyed'] = round((float) $stat['iskDestroyed'], 2);
            $fit['avgCost'] = isset($fitCosts[$hash]) ? round($fitCosts[$hash]['sum'] / max(1, $fitCosts[$hash]['count']), 2) : 0;
            $fit['sampleLossID'] = (int) ($stat['sampleLossID'] ?? $fit['sampleLossID'] ?? 0);
            $fit['shipName'] = $fit['shipName'] ?? $shipName;
            $fit['pip'] = $fit['pip'] ?? $pip;
            $fits[] = $fit;
        }
        foreach ($fits as $index => &$fit) {
            $fit['displayRank'] = $index + 1;
        }
        unset($fit);

        return ['fits' => $fits, 'windowDays' => 90, 'shipTypeID' => $shipTypeID, 'shipName' => $shipName, 'runID' => $runID];
    }

    public static function processFitKillerBatch($batch, &$stats, &$active, &$processed, &$victimLosses, &$fittedLosses, &$missingEsi, &$emptyFits, &$matchedKills)
    {
        global $mdb;

        if (sizeof($batch) == 0) return;

        $lossIDs = [];
        foreach ($batch as $mail) {
            $victim = self::victim($mail);
            if ($victim == null) continue;
            if ((int) ($victim['characterID'] ?? 0) <= 0 || (int) ($victim['shipTypeID'] ?? 0) <= 0) continue;
            if ((int) ($victim['groupID'] ?? 0) == 29) continue;
            if (!self::isShip((int) $victim['shipTypeID'])) continue;
            $lossIDs[] = (int) $mail['killID'];
        }

        $esiByKillID = [];
        if (sizeof($lossIDs) > 0) {
            $cursor = $mdb->getCollection('esimails')->find(
                ['killmail_id' => ['$in' => $lossIDs]],
                ['projection' => ['_id' => 0, 'killmail_id' => 1, 'victim.items' => 1, 'victim.ship_type_id' => 1]]
            );
            foreach ($cursor as $esimail) {
                $esiByKillID[(int) $esimail['killmail_id']] = $esimail;
            }
        }

        foreach ($batch as $mail) {
            $processed++;
            $killID = (int) ($mail['killID'] ?? 0);
            $mailTime = self::mailTime($mail);
            $attackers = self::attackerCount($mail);
            $weight = 1 / max(1, $attackers);

            foreach ((array) ($mail['involved'] ?? []) as $involved) {
                if (($involved['isVictim'] ?? null) !== false) continue;

                $characterID = (int) ($involved['characterID'] ?? 0);
                $shipTypeID = (int) ($involved['shipTypeID'] ?? 0);
                if ($characterID <= 0 || $shipTypeID <= 0) continue;

                $key = "$characterID:$shipTypeID";
                if (!isset($active[$key])) continue;

                $fitKey = $active[$key]['fitKey'];
                $active[$key]['kills']++;
                $stats[$fitKey]['kills']++;
                $stats[$fitKey]['weightedKills'] += $weight;
                $stats[$fitKey]['iskDestroyed'] += (float) ($mail['zkb']['totalValue'] ?? 0);
                if (($involved['finalBlow'] ?? false) === true) $stats[$fitKey]['finalBlows']++;
                if (($mail['solo'] ?? false) === true || $attackers == 1) $stats[$fitKey]['soloKills']++;
                $matchedKills++;
            }

            $victim = self::victim($mail);
            if ($victim == null) continue;

            $characterID = (int) ($victim['characterID'] ?? 0);
            $shipTypeID = (int) ($victim['shipTypeID'] ?? 0);
            if ($characterID <= 0 || $shipTypeID <= 0) continue;
            if ((int) ($victim['groupID'] ?? 0) == 29) continue;
            if (!self::isShip($shipTypeID)) continue;

            $victimLosses++;
            $key = "$characterID:$shipTypeID";
            self::closeFitKillerLife($key, $stats, $active);

            $esimail = $esiByKillID[$killID] ?? null;
            if ($esimail == null || !isset($esimail['victim']['items'])) {
                $missingEsi++;
                unset($active[$key]);
                continue;
            }
            if ((int) ($esimail['victim']['ship_type_id'] ?? 0) != $shipTypeID) {
                unset($active[$key]);
                continue;
            }

            $fit = self::signature($shipTypeID, $esimail['victim']['items']);
            if ($fit == null) {
                $emptyFits++;
                unset($active[$key]);
                continue;
            }

            $fittedLosses++;
            $fitKey = $fit['hash'];
            if (!isset($stats[$fitKey])) {
                $stats[$fitKey] = [
                    'hash' => $fitKey,
                    'shipTypeID' => $shipTypeID,
                    'shipName' => Info::getInfoField('typeID', $shipTypeID, 'name'),
                    'l_shipName' => strtolower((string) Info::getInfoField('typeID', $shipTypeID, 'name')),
                    'pip' => Info::getInfoField('typeID', $shipTypeID, 'pip'),
                    'parts' => $fit['parts'],
                    'losses' => 0,
                    'activeLives' => 0,
                    'bestLifeKills' => 0,
                    'kills' => 0,
                    'weightedKills' => 0,
                    'finalBlows' => 0,
                    'soloKills' => 0,
                    'iskDestroyed' => 0,
                    'pilots' => [],
                    'sampleLossID' => $killID,
                    'samplePilotID' => $characterID,
                ];
            }

            $stats[$fitKey]['losses']++;
            $stats[$fitKey]['pilots'][$characterID] = true;
            $active[$key] = ['fitKey' => $fitKey, 'kills' => 0, 'lossID' => $killID, 'lossTime' => $mailTime];
        }
    }

    public static function closeFitKillerLives(&$stats, &$active)
    {
        foreach (array_keys($active) as $key) {
            self::closeFitKillerLife($key, $stats, $active);
        }
    }

    public static function buildFitKillerRows($stats, $runID, $updated, $days, $processed, $victimLosses, $fittedLosses, $matchedKills, $minKills)
    {
        $rows = [];
        foreach ($stats as $row) {
            if ((int) $row['kills'] < $minKills) continue;

            $pilotCount = sizeof($row['pilots']);
            $row['pilotCount'] = $pilotCount;
            $row['weightedKillsPerLoss'] = $row['weightedKills'] / max(1, $row['losses']);
            $row['killsPerLoss'] = $row['kills'] / max(1, $row['losses']);
            $row['fitSlots'] = self::rows($row['parts']);
            $row['runID'] = $runID;
            $row['updated'] = $updated;
            $row['windowDays'] = $days;
            $row['processed'] = $processed;
            $row['victimLosses'] = $victimLosses;
            $row['fittedLosses'] = $fittedLosses;
            $row['matchedKills'] = $matchedKills;
            unset($row['pilots']);
            unset($row['parts']);
            $rows[] = $row;
        }

        usort($rows, function ($a, $b) {
            if ($a['kills'] == $b['kills']) {
                if ($a['weightedKills'] == $b['weightedKills']) return $a['losses'] <=> $b['losses'];
                return $b['weightedKills'] <=> $a['weightedKills'];
            }
            return $b['kills'] <=> $a['kills'];
        });

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['weightedKills'] = round($row['weightedKills'], 3);
            $row['weightedKillsPerLoss'] = round($row['weightedKillsPerLoss'], 3);
            $row['killsPerLoss'] = round($row['killsPerLoss'], 3);
            $row['iskDestroyed'] = round($row['iskDestroyed'], 2);
        }
        unset($row);

        return $rows;
    }

    public static function signature($shipTypeID, $items)
    {
        $parts = [];
        foreach ((array) $items as $item) {
            $slot = self::slot((int) ($item['flag'] ?? 0));
            if ($slot == null) continue;

            $typeID = (int) ($item['item_type_id'] ?? 0);
            if ($typeID <= 0 || !self::isFitType($typeID, $slot)) continue;

            $quantity = (int) ($item['quantity_destroyed'] ?? 0) + (int) ($item['quantity_dropped'] ?? 0);
            $quantity = max(1, $quantity);
            if (!isset($parts[$slot])) $parts[$slot] = [];
            $parts[$slot][$typeID] = ((int) ($parts[$slot][$typeID] ?? 0)) + $quantity;
        }

        if (sizeof($parts) == 0) return null;

        $tokens = [];
        foreach (self::slotOrder() as $slot) {
            if (!isset($parts[$slot])) continue;
            ksort($parts[$slot], SORT_NUMERIC);
            foreach ($parts[$slot] as $typeID => $quantity) $tokens[] = "$slot:$typeID:$quantity";
        }

        return [
            'hash' => substr(hash('sha256', $shipTypeID . '|' . implode('|', $tokens)), 0, 16),
            'parts' => $parts,
        ];
    }

    public static function rows($parts)
    {
        $rows = [];
        foreach (self::slotOrder() as $slot) {
            if (!isset($parts[$slot])) continue;

            $items = [];
            foreach ($parts[$slot] as $typeID => $quantity) {
                $items[] = [
                    'typeID' => (int) $typeID,
                    'name' => Info::getInfoField('typeID', (int) $typeID, 'name'),
                    'quantity' => (int) $quantity,
                ];
            }
            $rows[] = ['slot' => $slot, 'items' => $items];
        }
        return $rows;
    }

    private static function slot($flag)
    {
        if ($flag >= 11 && $flag <= 18) return 'Low';
        if ($flag >= 19 && $flag <= 26) return 'Mid';
        if ($flag >= 27 && $flag <= 34) return 'High';
        if ($flag >= 92 && $flag <= 98) return 'Rig';
        if ($flag >= 125 && $flag <= 132) return 'Sub';
        if ($flag == 87 || $flag == 158 || ($flag >= 159 && $flag <= 163)) return 'Drone';
        return null;
    }

    private static function isFitType($typeID, $slot)
    {
        static $categoryByTypeID = [];

        if (!isset($categoryByTypeID[$typeID])) $categoryByTypeID[$typeID] = (int) Info::getInfoField('typeID', $typeID, 'categoryID');

        if ($slot == 'Sub') return $categoryByTypeID[$typeID] == 32;
        if ($slot == 'Drone') return in_array($categoryByTypeID[$typeID], [18, 87]);
        return $categoryByTypeID[$typeID] == 7;
    }

    private static function slotOrder()
    {
        return ['Low', 'Mid', 'High', 'Rig', 'Sub', 'Drone'];
    }

    private static function closeFitKillerLife($key, &$stats, &$active)
    {
        if (!isset($active[$key])) return;

        $fitKey = $active[$key]['fitKey'];
        $kills = (int) $active[$key]['kills'];
        if ($kills > 0 && isset($stats[$fitKey])) {
            $stats[$fitKey]['activeLives']++;
            $stats[$fitKey]['bestLifeKills'] = max($stats[$fitKey]['bestLifeKills'], $kills);
        }
        unset($active[$key]);
    }
}
