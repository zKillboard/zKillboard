<?php

function handler($request, $response, $args, $container) {
    global $redis, $pugDebug;

    $params = $request->getQueryParams();
    $entityType = (string) ($params['type'] ?? '');
    $type = DailyStats::normalizeType($entityType);
    $id = $type == 'label' ? (string) ($params['id'] ?? '') : (int) ($params['id'] ?? 0);
    $side = in_array(($params['side'] ?? 'kills'), ['kills', 'losses']) ? $params['side'] : 'kills';
    $days = isset($params['days']) ? (string) $params['days'] : null;
    $date = isset($params['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $params['date']) ? (string) $params['date'] : null;
    $graph = isset($params['graph']) ? (string) $params['graph'] : null;
    $part = in_array(($params['part'] ?? 'history'), ['history', 'summary', 'topvalues', 'toplists', 'labels']) ? $params['part'] : 'history';
    $group = null;
    if ($part == 'toplists') {
        $groupParam = (string) ($params['group'] ?? '');
        if (isset(DailyStats::$topTypes[$groupParam])) {
            $group = $groupParam;
        }
    }

    if (!isset(DailyStats::$types[$type]) || $id === '' || ($type != 'label' && $id == 0)) {
        $response->getBody()->write('Invalid daily stats query');
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withHeader('Cache-Control', 'no-store')->withStatus(400);
    }

    $cacheTime = 900;
    $renderCacheTime = !empty($pugDebug) ? 0 : $cacheTime;
    $job = [
        'queryType' => 'dailyStats',
        'type' => $type,
        'entityType' => $entityType,
        'id' => $id,
        'side' => $side,
        'days' => $days,
        'date' => $date,
        'graph' => $graph,
        'part' => $part,
        'group' => $group,
        'cacheTime' => $cacheTime,
    ];
    $key = 'dailyStats:' . md5(serialize($job));
    $job['key'] = $key;
    $cacheTag = "www,asearch,dailyStats:$key,$type:$id";

    $rendered = $renderCacheTime > 0 ? $redis->get("rendered:$key") : false;
    if ($renderCacheTime > 0 && $rendered !== false && $rendered !== null && trim($rendered) !== '') {
        $response->getBody()->write($rendered);
        return dailyQueryCacheHeaders($response, $renderCacheTime)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
    }

    $rawResult = $redis->get("$key:result");
    if ($rawResult !== false && $rawResult !== null) {
        $result = unserialize($rawResult);
        if (!empty($result['processing'])) {
            $redis->del("$key:result");
            queueDailyStatsJob($redis, $key, $job);
            return renderDailyQueryProcessing($response, $cacheTag);
        }
        $rendered = renderDailyQueryPart($container, (array) $result, $part, $group);
        if ($renderCacheTime > 0) $redis->setex("rendered:$key", $renderCacheTime, $rendered);
        $redis->del("$key:result");
        $redis->del($key);
        $response->getBody()->write($rendered);
        return dailyQueryCacheHeaders($response, $renderCacheTime)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
    }

    if ($redis->get($key) !== 'PROCESSING') {
        queueDailyStatsJob($redis, $key, $job);
    }

    $waits = 0;
    do {
        usleep(100000);
        $rawResult = $redis->get("$key:result");
        if ($rawResult !== false && $rawResult !== null) {
            $result = unserialize($rawResult);
            if (!empty($result['processing'])) {
                $redis->del("$key:result");
                queueDailyStatsJob($redis, $key, $job);
                break;
            }
            $rendered = renderDailyQueryPart($container, (array) $result, $part, $group);
            if ($renderCacheTime > 0) $redis->setex("rendered:$key", $renderCacheTime, $rendered);
            $redis->del("$key:result");
            $redis->del($key);
            $response->getBody()->write($rendered);
            return dailyQueryCacheHeaders($response, $renderCacheTime)->withHeader('Content-Type', 'text/html; charset=utf-8')->withHeader('Cache-Tag', $cacheTag);
        }
        $waits++;
    } while ($waits <= 50);

    return renderDailyQueryProcessing($response, $cacheTag);
}

function queueDailyStatsJob($redis, $key, $job)
{
    $redis->setex($key, 900, 'PROCESSING');
    $redis->setex("$key:params", 3600, serialize($job));
    $redis->sadd('queueDailyStatsSet', $key);
}

function renderDailyQueryProcessing($response, $cacheTag)
{
    $response->getBody()->write('<div class="text-center p-4">Daily stats are processing...</div>');
    return $response
        ->withHeader('Content-Type', 'text/html; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store')
        ->withHeader('Cache-Tag', $cacheTag)
        ->withHeader('Retry-After', '3')
        ->withStatus(202);
}

function dailyQueryCacheHeaders($response, $cacheTime)
{
    $cacheTime = max(0, (int) $cacheTime);
    if ($cacheTime == 0) {
        return $response->withHeader('Cache-Control', 'no-store');
    }
    $cacheControl = "public, max-age=$cacheTime, s-maxage=$cacheTime";
    return $response
        ->withHeader('Cache-Control', $cacheControl)
        ->withHeader('CDN-Cache-Control', $cacheControl)
        ->withHeader('Cloudflare-CDN-Cache-Control', $cacheControl)
        ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + $cacheTime) . ' GMT');
}

function renderDailyQueryPart($container, $result, $part, $group = null)
{
    if ($part == 'toplists') {
        $side = in_array(($result['dailySide'] ?? 'kills'), ['kills', 'losses']) ? $result['dailySide'] : 'kills';
        $rendered = '';
        foreach ((array) ($result['dailyStats'][$side]['topLists'] ?? []) as $topList) {
            $topList = is_object($topList) ? (array) $topList : (array) $topList;
            $typeID = (string) ($topList['typeID'] ?? '');
            if ($group !== null && $typeID != $group) {
                continue;
            }
            $type = dailyAsearchTopListType($typeID, (string) ($topList['type'] ?? ''));
            $title = 'Top ' . Util::pluralize(ucwords($type));
            if ($type == 'shipType') $title = 'Top ShipTypes';
            if ($type == 'solarSystem') $title = 'Top SolarSystems';

            $rendered .= '<div class="pll-left" style="margin: 0px; padding-left: 1em;">';
            $rendered .= $container->get('view')->getEnvironment()->render('components/asearch_top_list.pug', ['topSet' => [
                'type' => $type,
                'singularTitle' => ucwords($type),
                'title' => $title,
                'values' => (array) ($topList['data'] ?? []),
                'sortKey' => 'killID',
                'sortBy' => -1,
            ]]);
            $rendered .= '</div>';

            if ($group !== null) {
                break;
            }
        }
        return $rendered . '<div class="clear"></div>';
    }

    $rendered = $container->get('view')->getEnvironment()->render('components/daily_stats.pug', $result);
    $start = "<!--daily-part-$part-start-->";
    $end = "<!--daily-part-$part-end-->";
    $startPos = strpos($rendered, $start);
    $endPos = strpos($rendered, $end);
    if ($startPos === false || $endPos === false || $endPos <= $startPos) {
        return $rendered;
    }

    $startPos += strlen($start);
    return trim(substr($rendered, $startPos, $endPos - $startPos));
}

function dailyAsearchTopListType($typeID, $fallback)
{
    $map = [
        'characterID' => 'character',
        'corporationID' => 'corporation',
        'allianceID' => 'alliance',
        'factionID' => 'faction',
        'shipTypeID' => 'shipType',
        'groupID' => 'group',
        'solarSystemID' => 'solarSystem',
        'regionID' => 'region',
        'locationID' => 'location',
    ];
    return $map[$typeID] ?? $fallback;
}
