<?php

function handler($request, $response, $args, $container) {
    $queryParams = $request->getQueryParams();
    $query = @$queryParams['query'];

$types = [
    'regionID',
    'solar_systemID',
    'itemID',
    'groupID',
    'factionID',
    'allianceID',
    'corporationID',
    'characterID',
    'categoryID',
    'locationID',
    'constellationID',
];

$result = zkbSearch::getResults(ltrim($query), null);
if (sizeof($result) == 0) $result = zkbSearch::getResults(trim($query), null);

$ret = [];
for ($i = 0; $i < sizeof($result); $i++) {
    $row = $result[$i];
    //if ($row['data']['groupBy'] == 'undefined' || $row['data']['groupBy'] == 'item') continue;
    if ($row['type'] == null) continue;
    if ($row['type'] == 'item') $row['type'] = 'ship';
    $add = [
            'value' => $row['name'],
            'data' => [
                    'type' => $row['type'],
                    'groupBy' => $row['type'] . 's',
                    'id' => $row['id']
                ]
            ];
    $ret[] = $add;
}

// CORS headers
$response = $response->withHeader('Access-Control-Allow-Origin', '*');
$response = $response->withHeader('Access-Control-Allow-Methods', 'GET');

$json = json_encode(['suggestions' => $ret]);
$response->getBody()->write($json);
return $response->withHeader('Content-Type', 'application/json; charset=utf-8');

}
