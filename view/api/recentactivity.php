<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $twig;

    $types = ['characterID', 'corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID'];

    foreach ($types as $type) {
        $arr = $mdb->getCollection('ninetyDays')->distinct("involved.$type");
        if (@$arr[0] === null) array_shift($arr);
        if (@$arr[0] === 0) array_shift($arr);
        $json[$type] = $arr;
    }

    $response->getBody()->write(json_encode($json));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
}
