<?php

$query = @$_GET['query'];

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


// Declare out json return type
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['content_type'] = 'application/json; charset=utf-8';
} else {
	$app->contentType('application/json; charset=utf-8');
}

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

echo json_encode(['suggestions' => $ret]);
