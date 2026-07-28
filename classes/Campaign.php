<?php

use cvweiss\redistools\RedisCache;
use MongoDB\BSON\ObjectId;

class Campaign
{
    const MAX_SPAN_SECONDS = 31622400;
    const RESULT_CACHE_SECONDS = 900;
    const KILLMAIL_LIMIT = 100;

    public static function normalizeFilters($filters, $implyEndDate = true)
    {
        $filters = is_object($filters) ? (array) $filters : $filters;
        if (!is_array($filters)) return [];

        $normalized = [];
        $buttons = [];
        foreach ((array) ($filters['buttons'] ?? []) as $button) {
            $button = preg_replace('/[^a-zA-Z0-9:+_\- #]/', '', (string) $button);
            if ($button == '' || $button == 'inferred-fits') continue;
            if (preg_match('/^page\d+$/', $button)) continue;
            $buttons[] = $button;
        }
        if (!in_array('page1', $buttons, true)) $buttons[] = 'page1';
        $normalized['buttons'] = array_values(array_unique($buttons));

        foreach (['attackers', 'neutrals', 'victims', 'location', 'items'] as $key) {
            $rows = [];
            foreach ((array) ($filters[$key] ?? []) as $row) {
                $row = is_object($row) ? (array) $row : $row;
                if (!is_array($row)) continue;

                $type = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($row['type'] ?? ''));
                $id = (int) ($row['id'] ?? 0);
                if ($type == '' || $id <= 0) continue;
                $clean = ['type' => $type, 'id' => $id];
                if (!empty($row['pip'])) $clean['pip'] = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) $row['pip']);
                $rows[] = $clean;
            }
            if (!empty($rows)) $normalized[$key] = $rows;
        }

        foreach (['dtstart', 'dtend'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value == '') continue;
            $time = strtotime($value);
            if ($time === false) continue;
            $normalized[$key] = gmdate('Y-m-d\TH:i', $time);
        }
        if ($implyEndDate && !empty($normalized['dtstart']) && empty($normalized['dtend']) && in_array('custom', $normalized['buttons'], true)) {
            $startTime = self::filterTime($normalized, 'dtstart');
            $endTime = self::impliedEndTime($startTime);
            if ($endTime > $startTime) $normalized['dtend'] = gmdate('Y-m-d\TH:i', $endTime);
        }

        $normalized['includeAssociates'] = ($filters['includeAssociates'] ?? true) !== false && (string) ($filters['includeAssociates'] ?? 'true') !== 'false';
        return $normalized;
    }

    public static function validateFilters($filters)
    {
        $filters = self::normalizeFilters($filters);
        $errors = [];

        $missingAttacker = empty($filters['attackers']);
        $missingVictim = empty($filters['victims']);
        if ($missingAttacker && $missingVictim) $errors[] = 'Add at least one attacker and at least one defender.';
        else if ($missingAttacker) $errors[] = 'Add at least one attacker.';
        else if ($missingVictim) $errors[] = 'Add at least one defender.';
        if (!empty($filters['neutrals'])) $errors[] = 'Remove filters from Either.';

        if (empty($filters['dtstart']) || !in_array('custom', (array) ($filters['buttons'] ?? []), true)) {
            $errors[] = 'Select a custom start date.';
        } else {
            $startTime = self::filterTime($filters, 'dtstart');
            $endTime = self::filterTime($filters, 'dtend');
            if ($startTime <= 0) {
                $errors[] = 'Select a valid custom start date.';
            } else if ($endTime > 0) {
                if ($endTime <= $startTime) $errors[] = 'Select an end date after the start date.';
                if ($endTime > self::impliedEndTime($startTime)) $errors[] = 'Campaigns can cover at most one year.';
            }
        }

        return $errors;
    }

    public static function hasSearchFilters($filters)
    {
        foreach (['attackers', 'neutrals', 'victims', 'location', 'items'] as $key) {
            if (!empty($filters[$key])) return true;
        }
        foreach ((array) ($filters['buttons'] ?? []) as $button) {
            if (str_starts_with((string) $button, 'label-')) return true;
        }
        return false;
    }

    public static function filterKey($filters, $implyEndDate = true)
    {
        $filters = self::normalizeFilters($filters, $implyEndDate);
        $buttons = (array) ($filters['buttons'] ?? []);
        sort($buttons);
        $filters['buttons'] = $buttons;
        foreach (['attackers', 'neutrals', 'victims', 'location', 'items'] as $key) {
            if (empty($filters[$key])) continue;
            usort($filters[$key], function ($a, $b) {
                return [$a['type'] ?? '', $a['id'] ?? 0, $a['pip'] ?? ''] <=> [$b['type'] ?? '', $b['id'] ?? 0, $b['pip'] ?? ''];
            });
        }
        return md5(json_encode($filters, JSON_UNESCAPED_SLASHES));
    }

    public static function jsonResponse($response, $payload, $status = 200, $cacheTag = 'www,account,campaign')
    {
        $response->getBody()->write(json_encode($payload));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Cache-Tag', $cacheTag);
    }

    public static function requestBody($request)
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) $body = (array) $request->getParsedBody();
        return $body;
    }

    public static function filtersFromBody($body, $implyEndDate = true)
    {
        $filters = $body['filters'] ?? [];
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
            if (!is_array($filters)) $filters = [];
        }
        return self::normalizeFilters($filters, $implyEndDate);
    }

    public static function visibilityFromBody($body, $default = true)
    {
        if (!array_key_exists('visibility', $body)) return (bool) $default;
        return (string) $body['visibility'] != 'private';
    }

    public static function title($campaign)
    {
        $filters = self::campaignFilters($campaign);

        $campaignTitle = self::sideTitle($filters, 'attackers', 'Attackers') . ' v. ' . self::sideTitle($filters, 'victims', 'Defenders');
        $parts = [];
        foreach (['neutrals' => 'Either', 'location' => 'Location', 'items' => 'Items'] as $key => $label) {
            $names = self::filterEntityNames($filters, $key);
            if (!empty($names)) $parts[] = $label . ': ' . self::compactNames($names);
        }

        $buttons = (array) ($filters['buttons'] ?? []);
        $epochTitles = [
            'week' => 'Last 7 Days',
            'recent' => 'Last 90 Days',
            'alltime' => 'Alltime',
            'current month' => 'Current Month',
            'prior month' => 'Prior Month',
            'custom' => 'Custom Date Range',
        ];
        foreach ($epochTitles as $button => $epochTitle) {
            if (in_array($button, $buttons, true)) {
                if ($button == 'custom' && !empty($filters['dtstart'])) {
                    $dateTitle = substr(str_replace('T', ' ', $filters['dtstart']), 0, 10);
                    if (!empty($filters['dtend'])) $dateTitle .= ' to ' . substr(str_replace('T', ' ', $filters['dtend']), 0, 10);
                    $parts[] = $dateTitle;
                    break;
                }
                $parts[] = $epochTitle;
                break;
            }
        }

        $labelNames = [];
        foreach (AdvancedSearch::$labels as $group) {
            foreach ($group as $labelID => $labelName) $labelNames[$labelID] = $labelName;
        }
        foreach ($buttons as $button) {
            if (!str_starts_with((string) $button, 'label-')) continue;
            $labelID = substr((string) $button, 6);
            $parts[] = $labelNames[$labelID] ?? $labelID;
        }

        $sortBy = 'date';
        $sortDir = 'desc';
        foreach ($buttons as $button) {
            if (!str_starts_with((string) $button, 'sort-')) continue;
            $sort = substr((string) $button, 5);
            if (in_array($sort, ['date', 'isk', 'involved', 'damage', 'points'], true)) $sortBy = $sort;
            if (in_array($sort, ['asc', 'desc'], true)) $sortDir = $sort;
        }
        $sortNames = ['date' => 'Date', 'isk' => 'ISK', 'involved' => 'Involved', 'damage' => 'Damage', 'points' => 'Points'];
        $sortTitle = ($sortNames[$sortBy] ?? ucwords($sortBy)) . ' ' . ucfirst($sortDir);
        if ($sortTitle != 'Date Desc') $parts[] = $sortTitle;

        if (!empty($parts)) $campaignTitle .= ', ' . implode(', ', array_values(array_unique($parts)));
        return $campaignTitle;
    }

    private static function filterEntityNames($filters, $key)
    {
        $names = [];
        foreach ((array) ($filters[$key] ?? []) as $row) {
            $entity = self::filterEntity($row);
            if (($entity['name'] ?? '') != '') $names[] = $entity['name'];
        }
        return array_values(array_unique($names));
    }

    private static function sideTitle($filters, $key, $fallback)
    {
        $names = self::filterEntityNames($filters, $key);
        if (empty($names)) return $fallback;
        return self::compactNames($names);
    }

    private static function compactNames($names)
    {
        $names = array_values(array_unique(array_filter((array) $names)));
        if (count($names) <= 2) return implode(' + ', $names);
        return $names[0] . ' + ' . (count($names) - 1) . ' more';
    }

    public static function listRow($campaign)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) $campaign = [];

        $uid = (string) ($campaign['_id'] ?? '');
        return [
            'uid' => $uid,
            'title' => self::title($campaign),
            'ownerName' => (string) ($campaign['ownerName'] ?? ''),
            'url' => "/campaign/$uid/",
            'public' => ($campaign['public'] ?? true) === true,
        ];
    }

    public static function find($uid)
    {
        global $mdb;

        try {
            return $mdb->findDoc('campaigns', ['_id' => new ObjectId((string) $uid)]);
        } catch (Exception $ex) {
            return null;
        }
    }

    public static function findOwned($uid, $userID)
    {
        global $mdb;

        try {
            return $mdb->findDoc('campaigns', ['_id' => new ObjectId((string) $uid), 'userID' => (int) $userID]);
        } catch (Exception $ex) {
            return null;
        }
    }

    public static function canView($campaign)
    {
        return $campaign != null;
    }

    public static function swapSides($campaign)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) return [];

        $filters = is_object($campaign['filters'] ?? null) ? (array) $campaign['filters'] : ($campaign['filters'] ?? []);
        $attackers = $filters['attackers'] ?? [];
        $filters['attackers'] = $filters['victims'] ?? [];
        $filters['victims'] = $attackers;
        $campaign['filters'] = $filters;

        return $campaign;
    }

    public static function sideEntities($campaign, $side)
    {
        $filters = self::campaignFilters($campaign);
        $entities = [];
        $seen = [];
        foreach ((array) ($filters[$side] ?? []) as $row) {
            $entity = self::filterEntity($row);
            if (empty($entity)) continue;
            $key = ($entity['url'] ?? '') . ':' . ($entity['name'] ?? '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $entities[] = $entity;
        }

        return $entities;
    }

    public static function sideIDs($campaign, $side)
    {
        $filters = self::campaignFilters($campaign);
        $ids = [];
        foreach ((array) ($filters[$side] ?? []) as $row) {
            $row = is_object($row) ? (array) $row : $row;
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) $ids[$id] = $id;
        }
        return array_values($ids);
    }

    public static function publicQuery()
    {
        return ['$or' => [['public' => true], ['public' => ['$exists' => false]]]];
    }

    public static function publicCampaigns($limit = 100)
    {
        global $mdb;

        return $mdb->find('campaigns', self::publicQuery(), ['created' => -1], max(1, min(100, (int) $limit)));
    }

    public static function userCampaigns($userID, $limit = 100)
    {
        global $mdb;

        return $mdb->find('campaigns', ['userID' => (int) $userID], ['created' => -1], max(1, min(100, (int) $limit)));
    }

    public static function matchingCampaigns($filters, $limit = 5, $userID = 0)
    {
        global $mdb;

        if ((int) $userID <= 0) return [];

        $legacyFilters = self::normalizeFilters($filters, false);
        $filters = self::normalizeFilters($filters);
        if (!self::hasSearchFilters($filters)) return [];
        if (count(self::validateFilters($filters)) > 0) return [];

        $filterKeys = array_values(array_unique([self::filterKey($filters), self::filterKey($legacyFilters, false)]));
        $limit = max(1, min(5, (int) $limit));
        $query = [
            'userID' => (int) $userID,
            '$or' => [
                ['filterKey' => ['$in' => $filterKeys]],
                ['filterKey' => ['$exists' => false], 'filters' => $filters],
                ['filterKey' => ['$exists' => false], 'filters' => $legacyFilters],
            ],
        ];
        return $mdb->find('campaigns', $query, ['created' => -1], $limit);
    }

    public static function updateFilters($uid, $userID, $filters, $public = null)
    {
        global $mdb;

        try {
            $filters = self::normalizeFilters($filters);
            $set = ['filters' => $filters, 'filterKey' => self::filterKey($filters), 'updated' => $mdb->now()];
            if ($public !== null) $set['public'] = (bool) $public;
            $result = $mdb->getCollection('campaigns')->updateOne(
                ['_id' => new ObjectId((string) $uid), 'userID' => (int) $userID],
                ['$set' => $set, '$unset' => ['name' => '', 'sideNames' => '']]
            );
            if ($result->getMatchedCount() > 0) {
                self::clearCacheTags($uid);
                return true;
            }
            return false;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function delete($uid, $userID)
    {
        global $mdb;

        try {
            $result = $mdb->getCollection('campaigns')->deleteOne(['_id' => new ObjectId((string) $uid), 'userID' => (int) $userID]);
            if ($result->getDeletedCount() > 0) {
                self::clearCacheTags($uid);
                return true;
            }
            return false;
        } catch (Exception $ex) {
            return false;
        }
    }

    public static function clearCacheTags($uid = '')
    {
        global $redis;

        $tags = ['campaigns', 'account'];
        $uid = trim((string) $uid);
        if ($uid != '') $tags[] = "campaign:$uid";
        $redis->sadd('queueCacheTags', ...$tags);
    }

    public static function searchUrl($campaign, $modify = false)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) $campaign = [];

        $uid = (string) ($campaign['_id'] ?? '');
        $campaignParam = ($modify && $uid != '') ? '?campaign=' . rawurlencode($uid) : '';
        return '/asearch/' . $campaignParam . '#' . rawurlencode(json_encode(self::campaignFilters($campaign), JSON_UNESCAPED_SLASHES));
    }

    public static function getKillIDs($campaign)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) $campaign = [];

        $uid = (string) ($campaign['_id'] ?? '');
        $filters = self::campaignFilters($campaign);
        $cacheKey = 'campaign:part:v1:' . $uid . ':killIDs:' . md5(json_encode($filters));
        $cached = RedisCache::get($cacheKey);
        if ($cached !== null) return array_slice(array_values((array) $cached), 0, self::KILLMAIL_LIMIT);

        $params = self::filtersToQueryParams($filters);
        $killIDs = self::directionalKillIDs($filters);
        $sortBy = (string) ($params['radios']['sort']['sortBy'] ?? 'date');
        $sortDir = (string) ($params['radios']['sort']['sortDir'] ?? 'desc');

        if ($sortBy == 'date') {
            usort($killIDs, fn($a, $b) => $sortDir == 'asc' ? ($a <=> $b) : ($b <=> $a));
        } else {
            $kills = Kills::getDetails($killIDs, true);
            self::sortKills($kills, $params);
            $killIDs = [];
            foreach ($kills as $kill) {
                $killID = (int) ($kill['killID'] ?? 0);
                if ($killID > 0) $killIDs[] = $killID;
            }
        }

        $killIDs = array_slice(array_values($killIDs), 0, self::KILLMAIL_LIMIT);
        RedisCache::set($cacheKey, $killIDs, self::RESULT_CACHE_SECONDS);
        return $killIDs;
    }

    public static function getSideStats($campaign)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) $campaign = [];

        $uid = (string) ($campaign['_id'] ?? '');
        $filters = self::campaignFilters($campaign);
        $params = self::filtersToQueryParams($filters);
        if (($params['epochbtn'] ?? '') == 'alltime') return null;

        $cacheKey = 'campaign:part:v2:' . $uid . ':sideStats:' . md5(json_encode($filters));
        $cached = RedisCache::get($cacheKey);
        if ($cached !== null) return $cached;

        $stats = [
            'attacker' => self::directionalSummary($filters),
            'defender' => self::directionalSummary(self::swappedFilters($filters)),
        ];
        RedisCache::set($cacheKey, $stats, self::RESULT_CACHE_SECONDS);
        return $stats;
    }

    public static function getTopSets($campaign, $victimsOnly)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) $campaign = [];

        $uid = (string) ($campaign['_id'] ?? '');
        $filters = self::campaignFilters($campaign);
        $part = $victimsOnly ? 'victims' : 'attackers';
        $sideName = $victimsOnly ? 'Defender' : 'Attacker';
        $cacheKey = 'campaign:part:v3:' . $uid . ':' . $part . ':' . md5(json_encode($filters));
        $cached = RedisCache::get($cacheKey);
        if ($cached !== null) return $cached;

        $sets = self::getSideTopSets($filters, $victimsOnly, $sideName);
        RedisCache::set($cacheKey, $sets, self::RESULT_CACHE_SECONDS);
        return $sets;
    }

    private static function directionalKillIDs($filters)
    {
        $killIDs = [];
        foreach ([$filters, self::swappedFilters($filters)] as $directionFilters) {
            $job = self::buildJob(self::filtersToQueryParams($directionFilters), 'kills');
            $result = AdvancedSearch::runQueuedQuery($job);
            foreach (($result['kills'] ?? []) as $killID) $killIDs[(int) $killID] = (int) $killID;
        }
        return array_values($killIDs);
    }

    private static function directionalSummary($filters)
    {
        $job = self::buildJob(self::filtersToQueryParams($filters), 'count');
        return AdvancedSearch::runQueuedQuery($job);
    }

    private static function swappedFilters($filters)
    {
        $campaign = self::swapSides(['filters' => $filters]);
        return $campaign['filters'] ?? [];
    }

    private static function campaignFilters($campaign)
    {
        $campaign = is_object($campaign) ? (array) $campaign : $campaign;
        if (!is_array($campaign)) return [];
        return self::normalizeFilters($campaign['filters'] ?? []);
    }

    private static function sortKills(&$kills, $params)
    {
        $sortBy = (string) ($params['radios']['sort']['sortBy'] ?? 'date');
        $sortDir = (string) ($params['radios']['sort']['sortDir'] ?? 'desc');
        usort($kills, function ($a, $b) use ($sortBy, $sortDir) {
            switch ($sortBy) {
                case 'isk':
                    $left = (float) ($a['zkb']['totalValue'] ?? 0);
                    $right = (float) ($b['zkb']['totalValue'] ?? 0);
                    break;
                case 'involved':
                    $left = (float) ($a['attackerCount'] ?? 0);
                    $right = (float) ($b['attackerCount'] ?? 0);
                    break;
                case 'damage':
                    $left = (float) ($a['damage_taken'] ?? 0);
                    $right = (float) ($b['damage_taken'] ?? 0);
                    break;
                case 'points':
                    $left = (float) ($a['zkb']['points'] ?? 0);
                    $right = (float) ($b['zkb']['points'] ?? 0);
                    break;
                default:
                    $left = (float) ($a['killID'] ?? 0);
                    $right = (float) ($b['killID'] ?? 0);
            }
            if ($left == $right) return 0;
            return ($sortDir == 'asc' ? ($left <=> $right) : ($right <=> $left));
        });
    }

    private static function filterEntity($row)
    {
        $row = is_object($row) ? (array) $row : $row;
        if (!is_array($row)) return [];

        $type = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($row['type'] ?? ''));
        $id = (int) ($row['id'] ?? 0);
        if ($type == '' || $id <= 0) return [];
        if ($type == 'shipID') $type = 'shipTypeID';
        if ($type == 'itemID') $type = 'typeID';
        if ($type == 'systemID') $type = 'solarSystemID';

        $labels = [
            'characterID' => 'Character',
            'corporationID' => 'Corporation',
            'allianceID' => 'Alliance',
            'factionID' => 'Faction',
            'shipTypeID' => 'Ship',
            'typeID' => 'Item',
            'groupID' => 'Group',
            'solarSystemID' => 'System',
            'constellationID' => 'Constellation',
            'regionID' => 'Region',
            'locationID' => 'Location',
        ];
        $urls = [
            'characterID' => 'character',
            'corporationID' => 'corporation',
            'allianceID' => 'alliance',
            'factionID' => 'faction',
            'shipTypeID' => 'ship',
            'typeID' => 'item',
            'groupID' => 'group',
            'solarSystemID' => 'system',
            'constellationID' => 'constellation',
            'regionID' => 'region',
            'locationID' => 'location',
        ];
        $name = (string) Info::getInfoField($type, $id, 'name');
        if ($name == '') $name = ($labels[$type] ?? $type) . ' ' . $id;

        $image = '/img/empty_32.png';
        $imageOnError = "this.removeAttribute('onerror'); this.src='/img/empty_32.png';";
        switch ($type) {
            case 'characterID':
                $image = "https://images.evetech.net/characters/$id/portrait?size=64";
                break;
            case 'corporationID':
                $image = "https://images.evetech.net/corporations/$id/logo?size=64";
                break;
            case 'allianceID':
                $image = "https://images.evetech.net/alliances/$id/logo?size=64";
                break;
            case 'factionID':
                $image = "https://images.evetech.net/corporations/$id/logo?size=64";
                break;
            case 'shipTypeID':
                $image = "https://images.evetech.net/types/$id/render?size=64";
                break;
            case 'typeID':
                $image = "https://images.evetech.net/types/$id/icon?size=64";
                $imageOnError = "this.onerror=function(){this.removeAttribute('onerror'); this.src='/img/icons/{$id}_64.png';}; this.src='https://images.evetech.net/types/$id/bp?size=64';";
                break;
            case 'groupID':
                $image = 'https://images.evetech.net/types/1/icon?size=64';
                break;
            case 'solarSystemID':
                $image = $id < 32000000 ? "/img/nohus/systems/$id.png" : '/img/empty_32.png';
                break;
            case 'constellationID':
                $image = "/img/nohus/constellations/$id.png";
                break;
            case 'regionID':
                $image = "/img/nohus/regions/$id.png";
                break;
            case 'locationID':
                $image = "https://image.eveonline.com/Type/{$id}_64.png";
                break;
        }

        return [
            'name' => $name,
            'url' => isset($urls[$type]) ? '/' . $urls[$type] . '/' . $id . '/' : '',
            'image' => $image,
            'imageOnError' => $imageOnError,
            'type' => $type,
            'id' => $id,
        ];
    }

    private static function getSideTopSets($filters, $victimsOnly, $sideName)
    {
        $prefix = $sideName;
        $sets = [];
        $groups = [
            'character' => 'Characters',
            'corporation' => 'Corporations',
            'alliance' => 'Alliances',
            'shipType' => 'Ships',
            'group' => 'Ship Groups',
        ];

        foreach ($groups as $groupType => $title) {
            if ($victimsOnly) {
                $rows = self::mergeTopRows(
                    self::sideTopRows($filters, $groupType, true),
                    self::sideTopRows(self::swappedFilters($filters), $groupType, false),
                    $groupType
                );
            } else {
                $rows = self::mergeTopRows(
                    self::sideTopRows($filters, $groupType, false),
                    self::sideTopRows(self::swappedFilters($filters), $groupType, true),
                    $groupType
                );
            }
            $rows = array_slice($rows, 0, 10);
            if (empty($rows)) continue;
            $sets[] = [
                'type' => $groupType,
                'singularTitle' => ucwords($groupType),
                'title' => "$prefix $title",
                'values' => $rows,
                'sortKey' => 'killID',
                'sortBy' => -1,
            ];
        }

        return $sets;
    }

    private static function sideTopRows($filters, $groupType, $victimsOnly)
    {
        $job = self::buildJob(self::filtersToQueryParams($filters), 'groups');
        $job['groupType'] = $groupType;
        $job['victimsOnly'] = $victimsOnly ? 'true' : 'false';
        return (array) AdvancedSearch::runQueuedQuery($job);
    }

    private static function mergeTopRows($left, $right, $groupType)
    {
        $field = $groupType . 'ID';
        $rows = [];
        foreach (array_merge((array) $left, (array) $right) as $row) {
            $row = is_object($row) ? (array) $row : $row;
            $id = (int) ($row[$field] ?? 0);
            if ($id <= 0) continue;
            if (!isset($rows[$id])) $rows[$id] = $row;
            else $rows[$id]['kills'] = (float) ($rows[$id]['kills'] ?? 0) + (float) ($row['kills'] ?? 0);
        }
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => ((float) ($b['kills'] ?? 0)) <=> ((float) ($a['kills'] ?? 0)));
        return $rows;
    }

    private static function filtersToQueryParams($filters)
    {
        $filters = self::normalizeFilters($filters);
        $params = [
            'labels' => [],
            'epochbtn' => 'week',
            'epoch' => ['start' => '', 'end' => ''],
            'radios' => ['sort' => ['sortBy' => 'date', 'sortDir' => 'desc'], 'page' => '1', 'group-agg-type' => 'all involved'],
            'includeAssociates' => !empty($filters['includeAssociates']) ? 'true' : 'false',
        ];

        foreach (['attackers', 'neutrals', 'victims', 'location', 'items'] as $key) {
            if (!empty($filters[$key])) $params[$key] = $filters[$key];
        }

        foreach ((array) ($filters['buttons'] ?? []) as $button) {
            $button = (string) $button;
            if (in_array($button, ['week', 'recent', 'alltime', 'custom'], true)) $params['epochbtn'] = $button;
            else if (str_starts_with($button, 'label-')) $params['labels'][] = substr($button, 6);
            else if (in_array($button, ['attackers-and', 'attackers-aand', 'attackers-or', 'either-and', 'either-aand', 'either-or', 'victims-and', 'victims-aand', 'victims-or', 'items-and', 'items-or'], true)) $params['labels'][] = $button;
            else if (str_starts_with($button, 'sort-')) {
                $sort = substr($button, 5);
                if (in_array($sort, ['date', 'isk', 'involved', 'damage', 'points'], true)) $params['radios']['sort']['sortBy'] = $sort;
                if (in_array($sort, ['asc', 'desc'], true)) $params['radios']['sort']['sortDir'] = $sort;
            } else if (preg_match('/^page([1-9]|10)$/', $button, $match)) {
                $params['radios']['page'] = $match[1];
            } else if ($button == 'victimsonly') {
                $params['radios']['group-agg-type'] = 'victims only';
            } else if ($button == 'attackersonly') {
                $params['radios']['group-agg-type'] = 'attackers only';
            }
        }

        if ($params['epochbtn'] == 'custom') {
            $params['epoch']['start'] = (string) ($filters['dtstart'] ?? '');
            $params['epoch']['end'] = (string) ($filters['dtend'] ?? '');
        }

        return $params;
    }

    private static function filterTime($filters, $key)
    {
        $value = trim((string) ($filters[$key] ?? ''));
        if ($value == '') return 0;
        $time = strtotime($value);
        return $time === false ? 0 : (int) $time;
    }

    private static function impliedEndTime($startTime)
    {
        $startTime = (int) $startTime;
        if ($startTime <= 0) return 0;

        try {
            return (new DateTimeImmutable('@' . $startTime))->setTimezone(new DateTimeZone('UTC'))->modify('+1 year')->getTimestamp();
        } catch (Exception $ex) {
            return $startTime + self::MAX_SPAN_SECONDS;
        }
    }

    private static function buildJob($queryParams, $queryType)
    {
        $types = ['character', 'corporation', 'alliance', 'group', 'region', 'solarSystem', 'shipType', 'faction', 'category', 'location', 'constellation'];
        $validSortBy = ['date' => 'killID', 'isk' => 'zkb.totalValue', 'involved' => 'attackerCount', 'damage' => 'damage_taken', 'points' => 'zkb.points'];
        $validSortDir = ['asc' => 1, 'desc' => -1];
        $buttons = (array) ($queryParams['labels'] ?? []);

        $query = [];
        $query = AdvancedSearch::buildQuery($queryParams, $query, 'neutrals', null, AdvancedSearch::getSelectedFromBase('either', $buttons), true);
        $query = AdvancedSearch::buildQuery($queryParams, $query, 'attackers', false, AdvancedSearch::getSelectedFromBase('attackers-', $buttons), true);
        $query = AdvancedSearch::buildQuery($queryParams, $query, 'victims', true, AdvancedSearch::getSelectedFromBase('victims-', $buttons), true);

        $filter = [];
        if (($queryParams['includeAssociates'] ?? 'true') !== 'true') {
            $filter = AdvancedSearch::buildQuery($queryParams, $filter, 'neutrals', null, AdvancedSearch::getSelectedFromBase('either', $buttons), false);
            $filter = AdvancedSearch::buildQuery($queryParams, $filter, 'attackers', false, AdvancedSearch::getSelectedFromBase('attackers-', $buttons), false);
            $filter = AdvancedSearch::buildQuery($queryParams, $filter, 'victims', true, AdvancedSearch::getSelectedFromBase('victims-', $buttons), false);
            if (sizeof($filter) == 1) $filter = $filter[0];
            else if (sizeof($filter) > 1) $filter = ['$and' => $filter];
        }

        $epochButton = (string) ($queryParams['epochbtn'] ?? 'week');
        $usePeriodCollectionOnly = in_array($epochButton, ['week', 'recent'], true);
        $query = AdvancedSearch::buildQuery($queryParams, $query, 'location', null, 'or');
        if (!$usePeriodCollectionOnly) {
            $query = AdvancedSearch::parseDate($queryParams, $query, 'start');
            $query = AdvancedSearch::parseDate($queryParams, $query, 'end');
        }

        $hasDateFilter = ($query['hasDateFilter'] ?? false) == true;
        $startTime = (int) ($query['start'] ?? 0);
        $now = time();

        $labels = [];
        foreach ($buttons as $label) {
            $group = AdvancedSearch::getLabelGroup($label);
            if ($group != null) $labels[$group][] = $label;
        }
        foreach ($labels as $search) $query[] = ['labels' => ['$in' => $search]];
        unset($query['start'], $query['end'], $query['hasDateFilter']);

        if (sizeof($query) == 0) $query = [];
        else if (sizeof($query) == 1) $query = $query[0];
        else $query = ['$and' => $query];

        $sortKey = $validSortBy[$queryParams['radios']['sort']['sortBy'] ?? 'date'] ?? 'killID';
        $sortBy = $validSortDir[$queryParams['radios']['sort']['sortDir'] ?? 'desc'] ?? -1;
        $groupAggType = (string) ($queryParams['radios']['group-agg-type'] ?? '');
        $victimsOnly = ($groupAggType == 'victims only' ? 'true' : ($groupAggType == 'attackers only' ? 'false' : 'null'));

        if ($epochButton == 'week') $coll = ['oneWeek'];
        else if ($epochButton == 'recent') $coll = ['ninetyDays'];
        else if ($sortKey == 'killID' && $sortBy == -1 && !$hasDateFilter) $coll = ['oneWeek', 'ninetyDays', 'killmails'];
        else $coll = ['killmails'];

        $aggregateCollection = self::getAggregateCollection($startTime, $now, $epochButton);

        return [
            'key' => 'campaign:' . md5(json_encode($queryParams) . $queryType),
            'queryType' => $queryType,
            'groupType' => '',
            'victimsOnly' => $victimsOnly,
            'coll' => $coll,
            'aggregateCollection' => $aggregateCollection,
            'page' => max(0, min(9, (int) ($queryParams['radios']['page'] ?? 1) - 1)),
            'sortKey' => $sortKey,
            'sortBy' => $sortBy,
            'sort' => [$sortKey => $sortBy],
            'query' => $query,
            'filter' => $filter,
            'types' => $types,
            'queryParams' => $queryParams,
            'itemJoin' => AdvancedSearch::getSelectedFromBase('items-', $buttons),
            'cacheTime' => self::RESULT_CACHE_SECONDS,
        ];
    }

    private static function getAggregateCollection($startTime, $now, $epochButton = '')
    {
        switch (trim((string) $epochButton)) {
            case 'week':
                return 'oneWeek';
            case 'recent':
            case 'current month':
            case 'prior month':
                return 'ninetyDays';
            case 'alltime':
                return 'killmails';
        }

        if ($startTime <= 0) return 'killmails';
        if ($startTime >= ($now - 604800)) return 'oneWeek';
        if ($startTime >= ($now - 7776000)) return 'ninetyDays';
        return 'killmails';
    }
}
