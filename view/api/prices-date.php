<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    $date = (string) ($args['date'] ?? '');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

    $response = $response->withHeader('Access-Control-Allow-Origin', '*')
                        ->withHeader('Access-Control-Allow-Methods', 'GET')
                        ->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withHeader('Cache-Control', 'public, max-age=28800')
                        ->withHeader('Cache-Tag', "www,api,prices,date:$date");

    if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
        $response->getBody()->write(json_encode(['error' => 'Invalid date; expected YYYY-MM-DD']));
        return $response->withStatus(400);
    }
    if ($date < '2003-01-01') {
        $response->getBody()->write(json_encode(['error' => 'Date must be on or after 2003-01-01']));
        return $response->withStatus(400);
    }
    if ($date > gmdate('Y-m-d')) {
        $response->getBody()->write(json_encode(['error' => 'Future dates are not allowed']));
        return $response->withStatus(400);
    }

    $cacheKey = "zkb:api:prices:date:$date";
    $json = $redis->get($cacheKey);
    if ($json === false) {
        // Most prices are found within a month. Project only those dates first.
        $projection = ['_id' => 0, 'typeID' => 1];
        $windowStart = max('2003-01-01', $parsedDate->modify('-30 days')->format('Y-m-d'));
        for ($day = $parsedDate; $day->format('Y-m-d') >= $windowStart; $day = $day->modify('-1 day')) {
            $projection[$day->format('Y-m-d')] = 1;
        }
        $prices = [];
        $fallbackPrices = [];
        $missing = [];
        $rows = $mdb->getCollection('prices')->find([], ['projection' => $projection, 'maxTimeMS' => 30000]);
        foreach ($rows as $row) {
            $typeID = (int) $row['typeID'];
            if (isset($row[$date])) {
                $prices[$typeID] = $row[$date];
                continue;
            }
            unset($row['typeID']);
            if ($row) {
                krsort($row);
                $fallbackPrices[$typeID] = reset($row);
            } else {
                $fallbackPrices[$typeID] = 0.01;
                $missing[] = $typeID;
            }
        }

        // Fetch older prices only for unresolved items, excluding all future dates.
        if ($missing && $windowStart > '2003-01-01') {
            $projection = ['_id' => 0, 'typeID' => 1];
            for (; $day->format('Y-m-d') >= '2003-01-01'; $day = $day->modify('-1 day')) {
                $projection[$day->format('Y-m-d')] = 1;
            }
            $rows = $mdb->getCollection('prices')->find(['typeID' => ['$in' => $missing]], [
                'projection' => $projection,
                'maxTimeMS' => 30000,
            ]);
            foreach ($rows as $row) {
                $typeID = (int) $row['typeID'];
                unset($row['typeID']);
                $latestDate = '';
                foreach ($row as $priceDate => $price) {
                    if ($priceDate > $latestDate) {
                        $latestDate = $priceDate;
                        $fallbackPrices[$typeID] = $price;
                    }
                }
            }
        }

        $specialTypeIDs = Price::getSpecialPriceTypeIDs();
        $groupPricedTypes = $mdb->find('information', [
            'type' => 'typeID',
            'groupID' => ['$in' => [29, 30, 659, 4513, 4759]],
        ], [], null, ['id' => 1]);
        foreach ($groupPricedTypes as $type) {
            $specialTypeIDs[] = (int) $type['id'];
        }
        foreach (array_unique($specialTypeIDs) as $typeID) {
            if (!isset($fallbackPrices[$typeID])) continue;
            $price = Price::getSpecialPrice($typeID, $date);
            if ($price !== null) $prices[(string) $typeID] = $price;
        }
        $prices += $fallbackPrices;
        ksort($prices, SORT_NUMERIC);
        $json = json_encode((object) $prices);
        $redis->setex($cacheKey, 3600, $json);
    }

    $queryParams = $request->getQueryParams();
    if (isset($queryParams['callback']) && Util::isValidCallback($queryParams['callback'])) {
        $response = $response->withHeader('Content-Type', 'application/javascript; charset=utf-8')
                            ->withHeader('X-JSONP', 'true');
        $response->getBody()->write($queryParams['callback'] . '(' . $json . ')');
        return $response;
    }

    $response->getBody()->write($json);
    return $response;
}
