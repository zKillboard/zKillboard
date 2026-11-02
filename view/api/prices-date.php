<?php

function handler($request, $response, $args, $container) {
    global $mdb;

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

    // Stream histories so items without a trade on this date are still included.
    $rows = $mdb->getCollection('prices')->find([], ['sort' => ['typeID' => 1], 'maxTimeMS' => 30000]);
    $prices = [];
    $fallbackPrices = [];
    foreach ($rows as $row) {
        $typeID = (int) $row['typeID'];
        if (isset($row[$date])) {
            $prices[$typeID] = $row[$date];
            continue;
        }
        unset($row['_id'], $row['typeID']);
        $latestDate = '';
        $fallbackPrices[$typeID] = 0.01;
        foreach ($row as $priceDate => $price) {
            if ($priceDate >= '2003-01-01' && $priceDate <= $date && $priceDate > $latestDate) {
                $latestDate = $priceDate;
                $fallbackPrices[$typeID] = $price;
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

    $queryParams = $request->getQueryParams();
    if (isset($queryParams['callback']) && Util::isValidCallback($queryParams['callback'])) {
        $response = $response->withHeader('Content-Type', 'application/javascript; charset=utf-8')
                            ->withHeader('X-JSONP', 'true');
        $response->getBody()->write($queryParams['callback'] . '(' . json_encode((object) $prices) . ')');
        return $response;
    }

    $response->getBody()->write(json_encode((object) $prices));
    return $response;
}
