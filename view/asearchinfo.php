<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis;

    try {
        $queryParams = $request->getQueryParams();
        $type = $queryParams['type'] ?? '';
        $otype = $type;
        if ($type == "systemID") $type = "solarSystemID";
        if ($type == "shipID") $type = "shipTypeID";
        $id = (int) ($queryParams['id'] ?? 0);

        $info = Info::getInfo($type, $id);
        $name = @$info['name'];
        if ($name == "") $name = "$type $id";

        if ($type ==  "solarSystemID") $name = "$name (" . Info::getInfoField('regionID', (int) @$info['regionID'], "name") . ")";

        $response = $response->withHeader('Access-Control-Allow-Methods', 'GET,POST')
                            ->withHeader('Content-Type', 'application/json; charset=utf-8');

        $type = $otype;
        $output = json_encode(['type' => $type, 'id' => $id, 'name' => $name], true);
        $response->getBody()->write($output);
        return $response;
    } catch (Exception $ex) {
        Util::zout(print_r($ex, true));
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        $response->getBody()->write('{"error": "Internal error"}');
        return $response;
    }
}
