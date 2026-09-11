<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $date = (string) ($args['date'] ?? '');
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

    $response = $response->withHeader('Access-Control-Allow-Origin', '*')
                        ->withHeader('Access-Control-Allow-Methods', 'GET')
                        ->withHeader('Content-Type', 'application/json; charset=utf-8')
                        ->withHeader('Cache-Control', 'public, max-age=600')
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

    $rows = $mdb->find('prices', [$date => ['$exists' => true]], ['typeID' => 1], null, ['typeID' => 1, $date => 1]);
    $prices = [];
    foreach ($rows as $row) {
        $prices[(string) $row['typeID']] = $row[$date];
    }

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
