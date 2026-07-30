<?php

class War
{
    const RESULT_CACHE_SECONDS = 900;

    public static function getWars($id, $active = true, $combined = false)
    {
        if (!self::isAlliance($id)) {
            $alliID = Db::queryField('select allianceID from zz_corporations where corporationID = :id', 'allianceID', array(':id' => $id));
            if ($alliID != 0) {
                $id = $alliID;
            }
        }
        $active = $active ? '' : 'not';
        $aggressing = Db::query("select * from zz_wars where aggressor = :id and timeFinished is $active null", array(':id' => $id));
        $defending = Db::query("select * from zz_wars where defender = :id and timeFinished is $active null", array(':id' => $id));
        if ($combined) {
            return array_merge($aggressing, $defending);
        }

        return array('agr' => $aggressing, 'dfd' => $defending);
    }

    public static function getKillIDWarInfo($killID)
    {
        global $mdb;
        $warID = $mdb->findField('killmails', 'warID', ['killID' => $killID]);

        return self::getWarInfo($warID);
    }

    public static function getWarInfo($warID)
    {
        global $mdb;
        $warInfo = array();
        if ($warID == null) {
            return $warInfo;
        }
        $warInfo = $mdb->findDoc('information', ['type' => 'warID', 'id' => $warID]);
        if (!isset($warInfo['aggressor'])) {
            return [];
        }

        $warInfo['warID'] = $warID;
        $agr = isset($warInfo['aggressor']['alliance_id']) ? $warInfo['aggressor']['alliance_id'] : (isset($warInfo['aggressor']['corporation_id']) ? $warInfo['aggressor']['corporation_id'] : $warInfo['aggressor']['id']);
        $agrIsAlliance = self::isAlliance($agr);
        $agrName = $agrIsAlliance ? Info::getInfoField('allianceID', $agr, 'name') : Info::getInfoField('corporationID', $agr, 'name');
        $warInfo['agrName'] = $agrName;
        $warInfo['agrLink'] = ($agrIsAlliance ? '/alliance/' : '/corporation/')."$agr/";

        $dfd = isset($warInfo['defender']['alliance_id']) ? $warInfo['defender']['alliance_id'] : (isset($warInfo['defender']['corporation_id']) ? $warInfo['defender']['corporation_id'] : $warInfo['defender']['id']);
        $dfdIsAlliance = self::isAlliance($dfd);
        $dfdName = $dfdIsAlliance ? Info::getInfoField('allianceID', $dfd, 'name') : Info::getInfoField('corporationID', $dfd, 'name');
        $warInfo['dfdName'] = $dfdName;
        $warInfo['dfdLink'] = ($dfdIsAlliance ? '/alliance/' : '/corporation/')."$dfd/";

        $warInfo['dscr'] = "$agrName vs $dfdName";

        return $warInfo;
    }

    public static function swapSides($war)
    {
        $war = is_object($war) ? (array) $war : $war;
        if (!is_array($war)) return [];

        $aggressor = $war['aggressor'] ?? [];
        $war['aggressor'] = $war['defender'] ?? [];
        $war['defender'] = $aggressor;

        foreach ([['agrName', 'dfdName'], ['agrLink', 'dfdLink']] as $keys) {
            $left = $war[$keys[0]] ?? null;
            $war[$keys[0]] = $war[$keys[1]] ?? null;
            $war[$keys[1]] = $left;
        }

        $war['dscr'] = trim((string) ($war['agrName'] ?? '')) . ' vs ' . trim((string) ($war['dfdName'] ?? ''));
        return $war;
    }

    public static function sideEntities($war, $side)
    {
        $entity = self::sideEntity($war, $side);
        return empty($entity) ? [] : [$entity];
    }

    public static function sideIDs($war, $side)
    {
        $entity = self::sideEntity($war, $side);
        $id = (int) ($entity['id'] ?? 0);
        return $id > 0 ? [$id] : [];
    }

    public static function asearchQueryBase($warID, $swapped = false)
    {
        $warID = (int) $warID;
        if ($warID <= 0) return '';

        $params = ['warID' => $warID];
        if ($swapped) $params['warSwap'] = '1';
        return '/asearchquery/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public static function topGroups()
    {
        return [
            'character' => ['field' => 'characterID', 'title' => 'Characters'],
            'corporation' => ['field' => 'corporationID', 'title' => 'Corporations'],
            'alliance' => ['field' => 'allianceID', 'title' => 'Alliances'],
            'shipType' => ['field' => 'shipTypeID', 'title' => 'Ships'],
            'solarSystem' => ['field' => 'solarSystemID', 'title' => 'Systems'],
        ];
    }

    public static function buildAsearchPartJob($queryParams)
    {
        $warID = (int) ($queryParams['warID'] ?? 0);
        if ($warID <= 0) return null;

        $warData = self::getWarInfo($warID);
        if (empty($warData)) return null;

        $part = (string) ($queryParams['part'] ?? '');
        $swapped = (string) ($queryParams['warSwap'] ?? '') == '1';
        if ($swapped) $warData = self::swapSides($warData);
        $baseJob = [
            'warID' => $warID,
            'warSwap' => $swapped,
            'cacheTime' => self::RESULT_CACHE_SECONDS,
            'attackerIDs' => self::sideIDs($warData, 'aggressor'),
            'attackerFilter' => self::sideFilter($warData, 'aggressor'),
            'defenderFilter' => self::sideFilter($warData, 'defender'),
        ];

        if ($part == 'kills') {
            return array_merge($baseJob, [
                'key' => "asearch:war:kills:$warID:" . ($swapped ? 'swap' : 'normal'),
                'queryType' => 'warKills',
                'part' => $part,
            ]);
        }

        if ($part == 'attackers' || $part == 'victims') {
            $topGroup = self::topGroup((string) ($queryParams['group'] ?? ''));
            if ($topGroup == null) return null;

            $victimsOnly = $part == 'victims';
            $directionKey = md5(json_encode([$baseJob['attackerFilter'], $baseJob['defenderFilter']], JSON_UNESCAPED_SLASHES));
            return array_merge($baseJob, [
                'key' => "asearch:war:top:v2:$warID:" . ($swapped ? 'swap' : 'normal') . ":$part:" . ($queryParams['group'] ?? '') . ":$directionKey",
                'queryType' => 'warTop',
                'part' => $part,
                'groupType' => (string) ($queryParams['group'] ?? ''),
                'victimsOnly' => $victimsOnly,
                'sideTitle' => $part == 'victims' ? 'Defender' : 'Attacker',
            ]);
        }

        return null;
    }

    public static function runQueuedAsearchPart($job)
    {
        $warID = (int) ($job['warID'] ?? 0);
        if ($warID <= 0) return [];

        switch ((string) ($job['queryType'] ?? '')) {
            case 'warKills':
                $result = AdvancedSearch::runQueuedQuery(self::buildAdvancedSearchJob($warID, 'kills'));
                return ['killIDs' => $result['kills'] ?? []];
            case 'warTop':
                $topGroup = self::topGroup((string) ($job['groupType'] ?? ''));
                if ($topGroup == null) return ['topSet' => []];

                $victimsOnly = !empty($job['victimsOnly']);
                $rows = AdvancedSearch::runQueuedQuery(self::buildAdvancedSearchJob($warID, 'groups', (string) ($job['groupType'] ?? ''), $victimsOnly, $job['attackerFilter'] ?? null, $job['defenderFilter'] ?? null));
                $sideTitle = (string) ($job['sideTitle'] ?? ($victimsOnly ? 'Defender' : 'Attacker'));
                return ['topSet' => Info::doMakeCommon("Top $sideTitle " . $topGroup['title'], $topGroup['field'], array_slice($rows, 0, 10))];
        }

        return [];
    }

    private static function buildAdvancedSearchJob($warID, $queryType, $groupType = '', $victimsOnly = false, $attackerFilter = null, $defenderFilter = null)
    {
        $sortKey = 'killID';
        $sortBy = -1;
        $query = [['warID' => $warID]];
        if (is_array($attackerFilter)) {
            $attackerQuery = AdvancedSearch::buildFromArray('attackers', false, 'and', true, ['attackers' => [$attackerFilter]]);
            if ($attackerQuery != null) $query[] = $attackerQuery;
        }
        if (is_array($defenderFilter)) {
            $defenderQuery = AdvancedSearch::buildFromArray('victims', true, 'and', true, ['victims' => [$defenderFilter]]);
            if ($defenderQuery != null) $query[] = $defenderQuery;
        }
        $directionKey = md5(json_encode([$attackerFilter, $defenderFilter], JSON_UNESCAPED_SLASHES));
        if (sizeof($query) == 1) $query = $query[0];
        else $query = ['$and' => $query];

        return [
            'key' => "war:$warID:$queryType:$groupType:" . ($victimsOnly ? 'victims' : 'attackers') . ":$directionKey",
            'queryType' => $queryType,
            'groupType' => $groupType,
            'victimsOnly' => $queryType == 'groups' ? ($victimsOnly ? 'true' : 'false') : 'null',
            'coll' => ['killmails'],
            'aggregateCollection' => 'killmails',
            'page' => 0,
            'sortKey' => $sortKey,
            'sortBy' => $sortBy,
            'sort' => [$sortKey => $sortBy],
            'query' => $query,
            'filter' => [],
            'types' => ['character', 'corporation', 'alliance', 'group', 'region', 'solarSystem', 'shipType', 'faction', 'category', 'location', 'constellation'],
            'queryParams' => ['warID' => $warID, 'attackers' => [$attackerFilter], 'victims' => [$defenderFilter]],
            'itemJoin' => 'and',
            'cacheTime' => self::RESULT_CACHE_SECONDS,
        ];
    }

    private static function topGroup($group)
    {
        return self::topGroups()[$group] ?? null;
    }

    private static function sideFilter($war, $side)
    {
        $entity = self::sideEntity($war, $side);
        $type = (string) ($entity['type'] ?? '');
        $id = (int) ($entity['id'] ?? 0);
        if ($type == '' || $id <= 0) return null;
        return ['type' => $type, 'id' => $id];
    }

    private static function sideEntity($war, $side)
    {
        $war = is_object($war) ? (array) $war : $war;
        if (!is_array($war)) return [];

        $row = is_object($war[$side] ?? null) ? (array) $war[$side] : ($war[$side] ?? []);
        if (!is_array($row)) return [];

        $id = (int) ($row['alliance_id'] ?? ($row['corporation_id'] ?? ($row['id'] ?? 0)));
        if ($id <= 0) return [];

        $link = (string) ($side == 'aggressor' ? ($war['agrLink'] ?? '') : ($war['dfdLink'] ?? ''));
        $type = isset($row['alliance_id']) || str_starts_with($link, '/alliance/') ? 'allianceID' : 'corporationID';
        $name = (string) ($side == 'aggressor' ? ($war['agrName'] ?? '') : ($war['dfdName'] ?? ''));
        if ($name == '') $name = (string) Info::getInfoField($type, $id, 'name');
        if ($name == '') $name = ($type == 'allianceID' ? 'Alliance ' : 'Corporation ') . $id;
        if ($link == '') $link = ($type == 'allianceID' ? '/alliance/' : '/corporation/') . "$id/";

        return [
            'name' => $name,
            'url' => $link,
            'image' => ($type == 'allianceID' ? "https://images.evetech.net/alliances/$id/logo?size=64" : "https://images.evetech.net/corporations/$id/logo?size=64"),
            'imageOnError' => "this.removeAttribute('onerror'); this.src='/img/empty_32.png';",
            'type' => $type,
            'id' => $id,
        ];
    }

    public static function getWarsPageTables($forceRefresh = false)
    {
        global $mdb, $redis;

        $cacheKey = 'zkb:wars:page:v3';
        if (!$forceRefresh) {
            try {
                $cached = $redis->get($cacheKey);
                if ($cached != null) {
                    $wars = unserialize($cached);
                    if (is_array($wars)) {
                        return $wars;
                    }
                }
            } catch (Exception $ex) {
                try {
                    $redis->del($cacheKey);
                } catch (Exception $ex) {
                }
            }
        }

        $fields = ['id' => 1, 'aggressor' => 1, 'defender' => 1, 'started' => 1, 'finished' => 1, 'timeStarted' => 1];
        $topLimit = 50;
        $recentFinished = gmdate('Y-m-d\TH:i:s\Z', time() - (90 * 86400));
        $wars = array();
        $wars[] = ['name' => 'Current Wars - Top Kills', 'wars' => iterator_to_array($mdb->getCollection('information')->aggregate([
            ['$match' => ['type' => 'warID', 'finished' => ['$exists' => false]]],
            ['$project' => $fields + ['totalKills' => ['$add' => [['$ifNull' => ['$aggressor.ships_killed', 0]], ['$ifNull' => ['$defender.ships_killed', 0]]]]]],
            ['$sort' => ['totalKills' => -1, 'started' => -1]],
            ['$limit' => $topLimit],
        ], ['allowDiskUse' => false, 'maxTimeMS' => 30000]))];
        $wars[] = ['name' => 'Recently Finished Wars - Top Kills', 'wars' => iterator_to_array($mdb->getCollection('information')->aggregate([
            ['$match' => ['type' => 'warID', 'finished' => ['$gte' => $recentFinished]]],
            ['$project' => $fields + ['totalKills' => ['$add' => [['$ifNull' => ['$aggressor.ships_killed', 0]], ['$ifNull' => ['$defender.ships_killed', 0]]]]]],
            ['$sort' => ['totalKills' => -1, 'finished' => -1]],
            ['$limit' => $topLimit],
        ], ['allowDiskUse' => false, 'maxTimeMS' => 30000]))];
        $wars[] = ['name' => 'Recent Declared Wars - Open to Allies', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'open_for_allies' => true], ['timeStarted' => -1], 50, $fields)];
        $wars[] = ['name' => 'Recent Declared Wars - Mutual', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID', 'mutual' => true], ['timeStarted' => -1], 50, $fields)];
        $wars[] = ['name' => 'Recently Declared Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['started' => -1], 25, $fields)];
        $wars[] = ['name' => 'Recently Finished Wars', 'wars' => $mdb->find('information', ['cacheTime' => 3600, 'type' => 'warID'], ['finished' => -1], 25, $fields)];
        foreach ($wars as &$warTable) {
            foreach ($warTable['wars'] as &$war) {
                foreach (['aggressor', 'defender'] as $side) {
                    if (!isset($war[$side]) || !is_array($war[$side]) || isset($war[$side]['name'])) {
                        continue;
                    }
                    $id = $war[$side]['alliance_id'] ?? ($war[$side]['corporation_id'] ?? ($war[$side]['id'] ?? 0));
                    if ((int) $id <= 0) {
                        continue;
                    }
                    $type = isset($war[$side]['alliance_id']) ? 'allianceID' : 'corporationID';
                    $name = Info::getInfoField($type, $id, 'name');
                    if ($name != null && $name != '') {
                        $war[$side]['name'] = $name;
                    }
                }
            }
        }
        unset($warTable, $war);

        try {
            $redis->setex($cacheKey, 3900, serialize($wars));
        } catch (Exception $ex) {
            // The page can still render directly if Redis is temporarily unavailable.
        }

        return $wars;
    }

    public static function isAlliance($entityID)
    {
        global $mdb;

        return $mdb->exists('information', ['type' => 'allianceID', 'id' => $entityID]);
    }
}
